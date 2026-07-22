<?php

use App\Actions\Conversations\CreateUserMessage;
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
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;

uses(RefreshDatabase::class);

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
        'ready_for_confirmation' => false,
        'safety_review_required' => true,
        'ready_for_approval' => true,
        'next_action' => 'review_and_approve',
    ]);

    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($assess->handle($workspace['plan']->refresh()))->toMatchArray([
        'ready_for_confirmation' => true,
        'ready_for_approval' => true,
        'next_action' => 'review_and_approve',
    ]);

    $first = app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $again = app(ConfirmMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($again->is($first))->toBeTrue()
        ->and($assess->handle($workspace['plan']->refresh()))->toMatchArray([
            'confirmed' => true,
            'ready_for_confirmation' => false,
            'next_action' => 'begin_shopping',
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
    $engine->shouldReceive('streamResponse')->once()->andReturn([
        new AssistantStreamChunk('complete'),
    ]);
    app()->instance(ChefConversationEngine::class, $engine);
    Log::spy();

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

    Log::shouldHaveReceived('error')->once()->withArgs(
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
    $engine->shouldReceive('streamResponse')->once()->andReturnUsing(function (): iterable {
        throw ValidationException::withMessages(['meal_slot_id' => 'Choose a current meal slot.']);
        yield;
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

it('confirms a ready plan through the same action used by the interface', function () {
    $workspace = planningReliabilityWorkspace(1);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots']->sole(),
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Satay chicken',
    );
    app(ReviewMealPlanSafety::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    ChefAgent::fake([
        new ToolCall('confirm-plan', 'ConfirmPlan', []),
        'The plan is confirmed. Next, I can build the shopping list.',
    ])->preventStrayPrompts();

    $message = app(CreateUserMessage::class)->handle(
        $workspace['conversation'],
        $workspace['user'],
        'Yes, confirm the plan.',
        (string) Str::uuid(),
    );
    $reply = app(ChefConversationEngine::class)->respondTo($workspace['conversation'], $message);

    expect($reply->content)->toContain('shopping list')
        ->and($workspace['plan']->refresh()->planning_confirmed_at)->not->toBeNull();
});
