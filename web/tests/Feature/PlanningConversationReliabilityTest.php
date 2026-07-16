<?php

use App\Actions\Conversations\CreateUserMessage;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantStreamChunk;
use App\Enums\MealSlotKind;
use App\Enums\MessageResponseStatus;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'next_action' => 'review_and_confirm',
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
        ->and($assistant->content)->toContain('All 3 meal slots are filled')
        ->and($assistant->content)->toContain('review and confirm')
        ->and($assistant->content)->toContain('shopping list')
        ->and($workspace['plan']->plannedMeals()->count())->toBe(3);
});

it('never persists a completed blank assistant message from any engine', function () {
    $workspace = planningReliabilityWorkspace(1);
    $engine = Mockery::mock(ChefConversationEngine::class);
    $engine->shouldReceive('streamResponse')->once()->andReturn([
        new AssistantStreamChunk('complete'),
    ]);
    app()->instance(ChefConversationEngine::class, $engine);

    $clientId = (string) Str::uuid();
    $response = $this->actingAs($workspace['user'])->post(route('conversations.messages.stream', $workspace['conversation']), [
        'content' => 'Please update dinner.',
        'client_message_id' => $clientId,
    ]);

    expect($response->streamedContent())->toContain('Chef could not finish that response')
        ->and($workspace['conversation']->messages()->where('role', 'assistant')->whereNotNull('in_reply_to_message_id')->count())->toBe(0)
        ->and($workspace['conversation']->messages()->where('client_message_id', $clientId)->sole()->response_status)->toBe(MessageResponseStatus::Failed);
});

it('confirms a ready plan through the same action used by the interface', function () {
    $workspace = planningReliabilityWorkspace(1);
    app(SelectPlannedMeal::class)->handle(
        $workspace['slots']->sole(),
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Satay chicken',
    );

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
