<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\Conversations\ResolvePendingPlanApproval;
use App\Actions\Households\RecordPreference;
use App\Actions\Households\RemovePreference;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantStreamChunk;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Conversation;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;
use Mockery\VerificationDirector;
use Tests\Support\MockExpectation;

/**
 * @return array{
 *     user: User,
 *     team: Team,
 *     plan: MealPlan,
 *     conversation: Conversation,
 *     person: Person,
 *     slots: Collection<int, MealSlot>
 * }
 */
function planningReliabilityWorkspace(int $slotCount = 3): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Planning family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays($slotCount - 1));
    $conversation = $plan->conversations->firstOrFail();
    $person = $team->people()->sole();
    $slots = collect();

    foreach (range(0, $slotCount - 1) as $offset) {
        $slots->push(app(CreateMealSlot::class)->handle(
            $plan,
            $user,
            today()->addDays($offset),
            MealSlotKind::Dinner,
            [$person],
        ));
    }

    return compact('user', 'team', 'plan', 'conversation', 'person', 'slots');
}

/** @param array<int, mixed> $responses */
function useResumableChefGateway(array $responses): void
{
    $manager = app(AiManager::class);
    $forgetRegisteredFake = Closure::bind(function (): void {
        unset($this->fakeAgentGateways[ChefAgent::class]);
    }, $manager, $manager);
    $forgetRegisteredFake();
    $manager->textProvider('openai')->useTextGateway(
        (new FakeTextGateway($responses))->preventStrayPrompts(),
    );
}

it('derives the next planning action from structured state and guards confirmation', function () {
    $workspace = planningReliabilityWorkspace(2);
    $assess = app(AssessMealPlanReadiness::class);

    expect($assess->handle($workspace['plan']))->toMatchArray([
        'total_slots' => 2,
        'filled_slots' => 0,
        'open_slots' => 2,
        'uncovered_slots' => 2,
        'ready_for_confirmation' => false,
        'next_action' => 'fill_open_slots',
    ]);

    expect(fn () => app(ConfirmMealPlan::class)->handle($workspace['plan'], $workspace['user']))
        ->toThrow(ValidationException::class);

    foreach ($workspace['slots'] as $index => $slot) {
        app(SelectPlannedMeal::class)->handle(
            $slot,
            $workspace['user'],
            PlannedMealType::Custom,
            title: 'Dinner '.($index + 1),
            servings: 2,
        );
    }

    expect($assess->handle($workspace['plan']->refresh()))->toMatchArray([
        'filled_slots' => 2,
        'open_slots' => 0,
        'ready_for_confirmation' => true,
        'safety_review_required' => false,
        'ready_for_approval' => true,
        'next_action' => 'review_and_approve',
    ]);

    $first = app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $again = app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($again->is($first))->toBeTrue()
        ->and($assess->handle($workspace['plan']->refresh()))->toMatchArray([
            'confirmed' => true,
            'ready_for_confirmation' => false,
            'next_action' => 'prepare_recipes',
        ]);
});

it('supersedes a prior pending proposal when a new draft is proposed for the same slot', function () {
    $workspace = planningReliabilityWorkspace(1);
    $firstMessage = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Suggest creamy garlic chicken pasta for Friday.',
        (string) Str::uuid(),
    );
    $secondMessage = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Swap Friday with chicken parmas.',
        (string) Str::uuid(),
    );

    $pasta = app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Creamy garlic chicken pasta',
        $workspace['slots'][0],
        message: $firstMessage,
    );
    $parma = app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Chicken parmigiana',
        $workspace['slots'][0],
        message: $secondMessage,
    );

    expect($pasta->refresh()->status)->toBe(MealProposalStatus::Replaced)
        ->and($pasta->decided_by_user_id)->toBe($workspace['user']->id)
        ->and($pasta->decided_at)->not->toBeNull()
        ->and($parma->refresh()->status)->toBe(MealProposalStatus::Pending)
        ->and($workspace['plan']->proposals()->where('status', MealProposalStatus::Pending)->count())->toBe(1)
        ->and(app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh()))->toMatchArray([
            'pending_proposals' => 1,
            'uncovered_slots' => 0,
            'ready_for_approval' => true,
        ]);
});

it('distinguishes uncovered slots from open slots with reviewable proposals', function () {
    $workspace = planningReliabilityWorkspace(2);
    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Please suggest two dinners for this plan.',
        (string) Str::uuid(),
    );
    $revision = $workspace['plan']->refresh()->revision;

    app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Butter chicken',
        $workspace['slots'][0],
        message: $message,
    );

    expect(app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh()))->toMatchArray([
        'open_slots' => 2,
        'uncovered_slots' => 1,
        'pending_proposals' => 1,
        'next_action' => 'fill_open_slots',
    ]);

    app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Beef tacos',
        $workspace['slots'][1],
        message: $message,
    );

    expect(app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh()))->toMatchArray([
        'open_slots' => 2,
        'uncovered_slots' => 0,
        'pending_proposals' => 2,
        'next_action' => 'resolve_proposals',
    ])->and($workspace['plan']->refresh()->revision)->toBe($revision);
});

it('removes preferences through an authorised reusable action', function () {
    $workspace = planningReliabilityWorkspace(1);
    $preference = app(RecordPreference::class)->handle(
        $workspace['team'],
        $workspace['user'],
        'Butter chicken',
        PreferenceSentiment::Like,
        PreferenceProvenance::Stated,
    );
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect(fn () => app(RemovePreference::class)->handle($preference, $outsider))
        ->toThrow(AuthorizationException::class);

    app(RemovePreference::class)->handle($preference, $workspace['user']);

    $this->assertDatabaseMissing('preferences', ['id' => $preference->id]);
});

it('turns an otherwise blank tool-only completion into a visible acknowledgement and confirmation handoff', function () {
    $workspace = planningReliabilityWorkspace(3);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots'][0],
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Chicken katsu curry with rice',
    );
    ChefAgent::fake([
        new ToolCall('select-two', 'SelectPlanMeal', [
            'meal_slot_id' => $workspace['slots'][1]->id,
            'type' => 'custom',
            'title' => 'Teriyaki chicken rice bowls',
            'servings' => 2,
        ]),
        new ToolCall('select-three', 'SelectPlanMeal', [
            'meal_slot_id' => $workspace['slots'][2]->id,
            'type' => 'custom',
            'title' => 'Beef burgers with oven chips and slaw',
            'servings' => 2,
        ]),
        '',
    ])->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'and 2 and 3',
        'client_message_id' => (string) Str::uuid(),
    ]);

    $stream = $response->streamedContent();
    $assistant = $workspace['conversation']->messages()->where('role', 'assistant')->reorder()->latest('id')->firstOrFail();

    expect($stream)->toContain('I completed these plan changes')
        ->and($assistant->content)->toContain('complete 3-meal draft')
        ->and($assistant->content)->toContain('Approving it will start')
        ->and($workspace['plan']->plannedMeals()->count())->toBe(3);
});

it('never persists a completed blank assistant message from any engine', function () {
    $workspace = planningReliabilityWorkspace(1);
    $engine = Mockery::mock(ChefConversationEngine::class);
    MockExpectation::for($engine, 'streamResponse')->andReturn([
        new AssistantStreamChunk('complete'),
    ]);
    app()->instance(ChefConversationEngine::class, $engine);
    $log = Log::spy();

    $clientId = (string) Str::uuid();
    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please update dinner.',
        'client_message_id' => $clientId,
    ]);

    $stream = $response->streamedContent();
    $message = $workspace['conversation']->messages()->where('client_message_id', $clientId)->sole();

    expect($stream)->toContain('"code":"empty_response"')
        ->and($stream)->toContain('"retryable":true')
        ->and($workspace['conversation']->messages()->where('role', 'assistant')->whereNotNull('in_reply_to_message_id')->count())->toBe(0)
        ->and($message->response_status)->toBe(MessageResponseStatus::Failed)
        ->and($message->response_error)->toBe('Chef finished without a response. Retry this message.')
        ->and($message->metadata['response']['attempts'])->toBe(1)
        ->and($message->metadata['response']['last_failure']['code'])->toBe('empty_response')
        ->and($message->metadata['response']['last_failure']['retryable'])->toBeTrue();

    $verification = $log->shouldHaveReceived('error');

    if (! $verification instanceof VerificationDirector) {
        throw new LogicException('Mockery did not create a log verification.');
    }

    $verification->once()->withArgs(
        fn (string $summary, array $context): bool => $summary === 'Chef conversation response failed.'
            && $context['failure_id'] === $message->metadata['response']['last_failure']['id']
            && $context['message_id'] === $message->id
            && $context['failure_code'] === 'empty_response'
            && ! array_key_exists('content', $context),
    );
});

it('classifies an escaped tool validation failure for an in-place retry', function () {
    $workspace = planningReliabilityWorkspace(1);
    $engine = Mockery::mock(ChefConversationEngine::class);
    MockExpectation::for($engine, 'streamResponse')->andReturnUsing(function (): iterable {
        throw ValidationException::withMessages(['meal_slot_id' => 'Choose a current meal slot.']);
    });
    app()->instance(ChefConversationEngine::class, $engine);

    $clientId = (string) Str::uuid();
    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please update that meal.',
        'client_message_id' => $clientId,
    ]);
    $stream = $response->streamedContent();
    $message = $workspace['conversation']->messages()->where('client_message_id', $clientId)->sole();

    expect($stream)->toContain('"code":"tool_error"')
        ->and($message->response_status)->toBe(MessageResponseStatus::Failed)
        ->and($message->metadata['response']['last_failure']['code'])->toBe('tool_error')
        ->and($message->response()->count())->toBe(0);
});

it('recovers a provider failure after creating a reviewable proposal', function () {
    $workspace = planningReliabilityWorkspace(2);
    $revision = $workspace['plan']->refresh()->revision;
    ChefAgent::fake([
        new ToolCall('inspect-plan', 'InspectMealPlan', []),
        new ToolCall('proposal', 'CreateMealProposal', [
            'title' => 'Butter chicken',
            'meal_slot_id' => $workspace['slots'][0]->id,
            'summary' => 'A quick weeknight butter chicken.',
            'estimated_minutes' => 30,
        ]),
        fn () => throw new RuntimeException('Provider failed after the tool result.'),
    ])->preventStrayPrompts();

    $clientId = (string) Str::uuid();
    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please suggest butter chicken.',
        'client_message_id' => $clientId,
    ]);
    $message = $workspace['conversation']->messages()->where('client_message_id', $clientId)->sole();

    expect($response->streamedContent())->toContain('I added these meal suggestions for review')
        ->toContain('Butter chicken')
        ->toContain('1 meal slot still needs an option')
        ->and($message->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($message->response()->count())->toBe(1)
        ->and($workspace['plan']->proposals()->count())->toBe(1)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision);
});

it('retries stale preference evidence and creates only the four uncovered proposals', function () {
    $workspace = planningReliabilityWorkspace(7);
    $source = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        "I'd love a butter chicken, beef tacos and a satay chicken.",
        (string) Str::uuid(),
    );
    $source->update(['response_status' => MessageResponseStatus::Completed, 'response_completed_at' => now()]);
    $workspace['conversation']->messages()->create([
        'team_id' => $workspace['team']->id,
        'role' => 'assistant',
        'content' => 'Those give us three dinner options. Would you like four more?',
        'in_reply_to_message_id' => $source->id,
    ]);

    foreach (['Butter chicken', 'Beef tacos', 'Satay chicken'] as $index => $title) {
        app(ProposeMeal::class)->handle(
            $workspace['plan'],
            $workspace['user'],
            $title,
            $workspace['slots'][$index],
            message: $source,
        );
    }

    $clientId = (string) Str::uuid();
    $failed = app(CreateUserMessage::class)->handle($workspace['conversation'], $workspace['user'], 'Please do!', $clientId);
    $failed->update([
        'response_status' => MessageResponseStatus::Failed,
        'response_error' => 'Chef could not finish that response. Retry this message.',
        'metadata' => ['response' => ['attempts' => 2]],
    ]);
    $revision = $workspace['plan']->refresh()->revision;
    $suggestions = [
        ['Lemon chicken tray bake', 'Chicken, lemon, and vegetables on one tray.'],
        ['Beef and broccoli noodles', 'Fast noodles with beef and crisp broccoli.'],
        ['Pesto chicken pasta', 'A quick pesto pasta with chicken and greens.'],
        ['Fish tacos', 'Simple fish tacos with crunchy slaw.'],
    ];
    $fake = [
        new ToolCall('inspect-plan', 'InspectMealPlan', []),
        new ToolCall('stale-preference', 'RecordHouseholdPreference', [
            'scope' => 'family',
            'subject' => 'butter chicken',
            'sentiment' => 'like',
            'provenance' => 'stated',
            'strength' => 3,
            'evidence_quote' => "I'd love a butter chicken, beef tacos and a satay chicken.",
        ]),
    ];

    foreach ($suggestions as $index => [$title, $summary]) {
        $fake[] = new ToolCall('proposal-'.$index, 'CreateMealProposal', [
            'title' => $title,
            'meal_slot_id' => $workspace['slots'][$index + 3]->id,
            'summary' => $summary,
            'estimated_minutes' => 30,
        ]);
    }

    $fake[] = 'I kept your first three choices and added four more quick dinner suggestions for review.';
    ChefAgent::fake($fake)->preventStrayPrompts();

    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please do!',
        'client_message_id' => $clientId,
    ]);
    $response->streamedContent();
    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    $replay = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please do!',
        'client_message_id' => $clientId,
    ]);
    $replay->streamedContent();

    expect($failed->response()->sole()->content)->toContain('added four more quick dinner suggestions')
        ->and($failed->refresh()->response_status)->toBe(MessageResponseStatus::Completed)
        ->and($failed->metadata['response']['attempts'])->toBe(3)
        ->and($failed->response()->count())->toBe(1)
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->count())->toBe(1)
        ->and($workspace['plan']->proposals()->count())->toBe(7)
        ->and($workspace['plan']->plannedMeals()->count())->toBe(0)
        ->and($workspace['team']->preferences()->count())->toBe(0)
        ->and($workspace['plan']->refresh()->revision)->toBe($revision)
        ->and($readiness)->toMatchArray([
            'open_slots' => 7,
            'uncovered_slots' => 0,
            'pending_proposals' => 7,
            'next_action' => 'resolve_proposals',
        ]);
});

it('pauses confirm plan for sdk approval and resumes through the same domain action', function () {
    $workspace = planningReliabilityWorkspace(1);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots']->sole(),
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Satay chicken',
    );
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    $approvalId = 'confirm-plan-call';
    $revision = $workspace['plan']->refresh()->revision;
    useResumableChefGateway([
        new ToolCall($approvalId, 'ConfirmPlan', ['plan_revision' => $revision]),
        'The plan is confirmed and its recipes are being prepared.',
    ]);

    $request = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'The plan looks ready.',
        'client_message_id' => (string) Str::uuid(),
    ]);
    $pendingStream = $request->streamedContent();
    $pending = app(ResolvePendingPlanApproval::class)->handle($workspace['conversation']);

    expect($pendingStream)->toContain('"type":"tool_approval_request"')
        ->and($workspace['plan']->refresh()->planning_confirmed_at)->toBeNull()
        ->and($workspace['conversation']->refresh()->ai_conversation_id)->not->toBeNull()
        ->and($pending)->toMatchArray([
            'id' => $approvalId,
            'tool' => 'ConfirmPlan',
            'plan_revision' => $revision,
        ]);

    $approval = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'approval' => ['id' => $approvalId, 'decision' => 'approve'],
        'client_message_id' => (string) Str::uuid(),
    ]);

    $approval->streamedContent();
    $approvalReply = $workspace['conversation']->messages()->where('role', 'assistant')->reorder()->latest('id')->firstOrFail();

    expect($approvalReply->content)->toContain('recipes are being prepared')
        ->and($workspace['plan']->refresh()->planning_confirmed_at)->not->toBeNull()
        ->and(app(ResolvePendingPlanApproval::class)->handle($workspace['conversation']))->toBeNull()
        ->and($workspace['conversation']->messages()->where('role', 'user')->reorder()->latest('id')->firstOrFail()->metadata['tool_approval'])->toBe([
            'id' => $approvalId,
            'decision' => 'approve',
        ]);
});

it('rejects or invalidates a pending sdk plan approval without changing the plan', function () {
    $workspace = planningReliabilityWorkspace(1);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots']->sole(),
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Satay chicken',
    );
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $revision = $workspace['plan']->refresh()->revision;
    $approvalId = 'reject-plan-call';
    useResumableChefGateway([
        new ToolCall($approvalId, 'ConfirmPlan', ['plan_revision' => $revision]),
        'No problem — the plan is unchanged.',
    ]);

    $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Show me the approval.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();

    $rejection = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'approval' => ['id' => $approvalId, 'decision' => 'reject'],
        'client_message_id' => (string) Str::uuid(),
    ]);

    $rejection->streamedContent();
    $rejectionReply = $workspace['conversation']->messages()->where('role', 'assistant')->reorder()->latest('id')->firstOrFail();

    expect($rejectionReply->content)->toContain('plan is unchanged')
        ->and($workspace['plan']->refresh()->planning_confirmed_at)->toBeNull()
        ->and(app(ResolvePendingPlanApproval::class)->handle($workspace['conversation']))->toBeNull();

    useResumableChefGateway([
        new ToolCall('stale-plan-call', 'ConfirmPlan', ['plan_revision' => $revision]),
    ]);
    $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Ask again.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();
    $workspace['plan']->increment('revision');

    $stale = $this->postJson(route('conversations.messages.stream', $workspace['conversation']), [
        'approval' => ['id' => 'stale-plan-call', 'decision' => 'approve'],
        'client_message_id' => (string) Str::uuid(),
    ]);

    $stale->assertConflict();
    expect($workspace['plan']->refresh()->planning_confirmed_at)->toBeNull();
});

it('persists an approved tool result before recovering from a provider failure', function () {
    $workspace = planningReliabilityWorkspace(1);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots']->sole(),
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Satay chicken',
    );
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    $approvalId = 'provider-failure-call';
    $revision = $workspace['plan']->refresh()->revision;
    useResumableChefGateway([
        new ToolCall($approvalId, 'ConfirmPlan', ['plan_revision' => $revision]),
        fn () => throw new RuntimeException('Provider stopped after the approved tool ran.'),
    ]);

    $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'The plan is ready.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();

    $approval = $this->post(route('conversations.messages.stream', $workspace['conversation']), [
        'approval' => ['id' => $approvalId, 'decision' => 'approve'],
        'client_message_id' => (string) Str::uuid(),
    ]);

    expect($approval->streamedContent())->toContain('Approved')
        ->and($workspace['plan']->refresh()->planning_confirmed_at)->not->toBeNull()
        ->and($workspace['conversation']->messages()->where('role', 'assistant')->reorder()->latest('id')->firstOrFail()->metadata['recovered_from_failure'])->toBeTrue();

    $pausedLedgerRow = DB::table(config('ai.conversations.tables.messages'))
        ->where('conversation_id', $workspace['conversation']->refresh()->ai_conversation_id)
        ->whereNotNull('approval_state')
        ->sole();

    $toolResults = json_decode($pausedLedgerRow->tool_results, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($toolResults)) {
        throw new RuntimeException('Expected the paused conversation ledger to contain tool results.');
    }

    $toolResultIds = [];

    foreach ($toolResults as $toolResult) {
        if (is_array($toolResult) && is_string($toolResult['id'] ?? null)) {
            $toolResultIds[] = $toolResult['id'];
        }
    }

    expect(json_decode($pausedLedgerRow->approval_state, true))->toBe(['pending' => []])
        ->and($toolResultIds)->toBe([$approvalId]);
});

it('fails closed for unknown and cross-conversation approval decisions', function () {
    $first = planningReliabilityWorkspace(1);
    $second = planningReliabilityWorkspace(1);

    foreach ([$first, $second] as $workspace) {
        app(SelectPlannedMeal::class)->handle(
            $workspace['slots']->sole(),
            $workspace['user'],
            PlannedMealType::Custom,
            title: 'Satay chicken',
        );
        app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    }

    useResumableChefGateway([
        new ToolCall('first-conversation-call', 'ConfirmPlan', [
            'plan_revision' => $first['plan']->refresh()->revision,
        ]),
    ]);
    $this->actingAs($first['user'])->post(route('conversations.messages.stream', $first['conversation']), [
        'content' => 'Review this plan.',
        'client_message_id' => (string) Str::uuid(),
    ])->streamedContent();

    $unknown = $this->postJson(route('conversations.messages.stream', $first['conversation']), [
        'approval' => ['id' => 'unknown-call', 'decision' => 'approve'],
        'client_message_id' => (string) Str::uuid(),
    ]);

    $crossConversation = $this->actingAs($second['user'])->postJson(
        route('conversations.messages.stream', $second['conversation']),
        [
            'approval' => ['id' => 'first-conversation-call', 'decision' => 'approve'],
            'client_message_id' => (string) Str::uuid(),
        ],
    );

    $unknown->assertConflict();
    $crossConversation->assertConflict();
    expect($first['plan']->refresh()->planning_confirmed_at)->toBeNull()
        ->and($second['plan']->refresh()->planning_confirmed_at)->toBeNull();
});
