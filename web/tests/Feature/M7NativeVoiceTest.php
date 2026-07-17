<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Enums\MealSlotKind;
use App\Enums\MessageRole;
use App\Enums\VoiceSessionStatus;
use App\Enums\VoiceToolCallStatus;
use App\Models\User;
use App\Models\VoiceSession;
use App\Voice\Contracts\RealtimeSessionBroker;
use App\Voice\OpenAiRealtimeSessionBroker;
use App\Voice\Testing\FakeRealtimeSessionBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

function nativeVoiceWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Voice family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $conversation = $plan->conversations()->firstOrFail();
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people()->get(),
    );

    return compact('user', 'team', 'plan', 'conversation', 'slot');
}

function startNativeVoiceSession(array $workspace): VoiceSession
{
    $response = test()->actingAs($workspace['user'])->postJson(
        route('conversations.realtime-sessions.store', $workspace['conversation']),
        ['sdp' => "v=0\r\no=browser 1 1 IN IP4 127.0.0.1\r\ns=Chef offer\r\nt=0 0\r\n"],
    );

    $response->assertCreated()
        ->assertJsonStructure(['voice_session_id', 'sdp', 'expires_at'])
        ->assertJsonMissing(['api_key']);

    return VoiceSession::query()->where('public_id', $response->json('voice_session_id'))->sole();
}

it('creates a team-scoped realtime session through the deterministic broker', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);
    $broker = app(FakeRealtimeSessionBroker::class);

    expect($session->status)->toBe(VoiceSessionStatus::Active)
        ->and($session->team_id)->toBe($workspace['team']->id)
        ->and($session->conversation_id)->toBe($workspace['conversation']->id)
        ->and($session->user_id)->toBe($workspace['user']->id)
        ->and($session->microphone_permission_granted_at)->not->toBeNull()
        ->and($session->expires_at->greaterThanOrEqualTo($session->created_at->addMinutes(59)))->toBeTrue()
        ->and($broker->connections)->toHaveCount(1)
        ->and($broker->connections[0]['offer_sdp'])->toStartWith('v=0');

    $this->actingAs($workspace['user'])->postJson(
        route('realtime-sessions.tool-calls.store', $session),
        [
            'provider_call_id' => 'call_with_extra_scope',
            'tool_name' => 'continue_chef_conversation',
            'arguments' => [
                'message' => 'Inspect the plan.',
                'unreviewed_scope' => 'checkout',
            ],
        ],
    )->assertUnprocessable()->assertJsonValidationErrors('arguments');
});

it('routes a voice turn through the same authorised chef tools and persists its audit trail idempotently', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);
    ChefAgent::fake([
        new ToolCall('voice-select', 'SelectPlanMeal', [
            'meal_slot_id' => $workspace['slot']->id,
            'type' => 'custom',
            'title' => 'Lemon chicken and roast vegetables',
            'servings' => 2,
        ]),
        'I added lemon chicken and roast vegetables to dinner.',
    ])->preventStrayPrompts();
    $payload = [
        'provider_call_id' => 'call_voice_123',
        'tool_name' => 'continue_chef_conversation',
        'arguments' => [
            'message' => 'Add lemon chicken and roast vegetables for dinner.',
        ],
    ];

    $response = $this->actingAs($workspace['user'])->postJson(
        route('realtime-sessions.tool-calls.store', $session),
        $payload,
    );

    $response->assertOk()
        ->assertJsonPath('assistant_response', 'I added lemon chicken and roast vegetables to dinner.')
        ->assertJsonPath('artifacts.after.meal_plan_id', $workspace['plan']->id);
    $plannedMeal = $workspace['slot']->plannedMeal()->sole();
    $call = $session->toolCalls()->sole();
    $userMessage = $workspace['conversation']->messages()
        ->where('role', MessageRole::User)
        ->where('content', $payload['arguments']['message'])
        ->sole();
    $assistant = $userMessage->response()->sole();
    $messageCount = $workspace['conversation']->messages()->count();

    expect($plannedMeal->title)->toBe('Lemon chicken and roast vegetables')
        ->and($call->status)->toBe(VoiceToolCallStatus::Completed)
        ->and($call->user_message_id)->toBe($userMessage->id)
        ->and($call->assistant_message_id)->toBe($assistant->id)
        ->and($userMessage->metadata)->toMatchArray([
            'input_mode' => 'voice',
            'voice_session_id' => $session->public_id,
            'voice_tool_call_id' => $call->id,
        ])
        ->and($assistant->metadata)->toMatchArray([
            'input_mode' => 'voice',
            'voice_session_id' => $session->public_id,
            'voice_tool_call_id' => $call->id,
        ])
        ->and($call->result['artifacts']['after']['plan_revision'])
        ->toBeGreaterThan($call->result['artifacts']['before']['plan_revision']);

    $this->postJson(route('realtime-sessions.tool-calls.store', $session), $payload)
        ->assertOk()
        ->assertJsonPath('assistant_message_id', $assistant->id);

    expect($workspace['conversation']->messages()->count())->toBe($messageCount)
        ->and($session->toolCalls()->count())->toBe(1)
        ->and($workspace['slot']->plannedMeal()->count())->toBe(1);
});

it('revokes microphone scope and rejects later voice tool calls', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);

    $this->actingAs($workspace['user'])
        ->deleteJson(route('realtime-sessions.destroy', $session))
        ->assertOk()
        ->assertJson(['ended' => true]);

    $session->refresh();
    expect($session->status)->toBe(VoiceSessionStatus::Ended)
        ->and($session->microphone_permission_revoked_at)->not->toBeNull()
        ->and($session->ended_at)->not->toBeNull();

    $this->postJson(route('realtime-sessions.tool-calls.store', $session), [
        'provider_call_id' => 'call_after_end',
        'tool_name' => 'continue_chef_conversation',
        'arguments' => ['message' => 'Change dinner.'],
    ])->assertUnprocessable()->assertJsonValidationErrors('voice_session');
});

it('returns a conflict instead of executing a duplicate turn concurrently', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);
    $payload = [
        'provider_call_id' => 'call_processing',
        'tool_name' => 'continue_chef_conversation',
        'arguments' => ['message' => 'Keep dinner simple.'],
    ];
    $session->toolCalls()->create([
        'team_id' => $session->team_id,
        'conversation_id' => $session->conversation_id,
        'user_id' => $workspace['user']->id,
        'provider_call_id' => $payload['provider_call_id'],
        'tool_name' => $payload['tool_name'],
        'arguments' => $payload['arguments'],
        'client_message_id' => (string) Str::uuid(),
        'status' => VoiceToolCallStatus::Processing,
        'started_at' => now(),
    ]);

    $this->actingAs($workspace['user'])
        ->postJson(route('realtime-sessions.tool-calls.store', $session), $payload)
        ->assertConflict();

    expect($workspace['conversation']->messages()->count())->toBe(1);
});

it('fails closed and revokes its audit scope when the realtime broker fails', function () {
    $workspace = nativeVoiceWorkspace();
    app()->instance(RealtimeSessionBroker::class, new class implements RealtimeSessionBroker
    {
        public function connect(VoiceSession $session, string $offerSdp): string
        {
            throw new RuntimeException('provider detail must not reach the browser');
        }
    });

    $this->actingAs($workspace['user'])->postJson(
        route('conversations.realtime-sessions.store', $workspace['conversation']),
        ['sdp' => "v=0\r\no=browser 1 1 IN IP4 127.0.0.1\r\ns=Chef offer\r\nt=0 0\r\n"],
    )->assertStatus(502)
        ->assertJsonPath('message', 'Voice is temporarily unavailable. You can keep typing to Chef.')
        ->assertJsonMissing(['provider detail must not reach the browser']);

    $session = VoiceSession::query()->latest('id')->sole();
    expect($session->status)->toBe(VoiceSessionStatus::Failed)
        ->and($session->microphone_permission_revoked_at)->not->toBeNull()
        ->and($session->ended_at)->not->toBeNull();
});

it('does not resolve another family realtime session through route binding', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);
    $otherUser = User::factory()->create();
    app(CreateTeamForUser::class)->handle($otherUser, 'Other voice family');

    $this->actingAs($otherUser)
        ->deleteJson(route('realtime-sessions.destroy', $session->public_id))
        ->assertNotFound();
});

it('uses the unified OpenAI WebRTC endpoint without exposing the permanent key', function () {
    $workspace = nativeVoiceWorkspace();
    $session = startNativeVoiceSession($workspace);
    config()->set('services.openai.api_key', 'server-only-key');
    config()->set('services.openai.realtime.endpoint', 'https://api.openai.com/v1/realtime/calls');
    Http::fake([
        'https://api.openai.com/v1/realtime/calls' => Http::response("v=0\r\no=OpenAI answer\r\n", 200),
    ]);

    $answer = app(OpenAiRealtimeSessionBroker::class)->connect(
        $session,
        "v=0\r\no=browser offer\r\n",
    );

    expect($answer)->toStartWith('v=0');
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/realtime/calls'
            && $request->hasHeader('Authorization', 'Bearer server-only-key')
            && $request->hasHeader('OpenAI-Safety-Identifier')
            && str_contains($request->body(), 'continue_chef_conversation')
            && str_contains($request->body(), 'gpt-realtime-2.1')
            && str_contains($request->body(), 'gpt-4o-mini-transcribe');
    });
});
