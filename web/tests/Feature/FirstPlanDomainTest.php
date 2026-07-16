<?php

use App\Actions\Conversations\SendMessageToChef;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\ChefConversationEngine;
use App\Ai\Data\AssistantReply;
use App\Enums\MealSlotKind;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('starts a durable first plan and its conversation', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');

    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        now()->startOfDay(),
        now()->startOfDay()->addDays(6),
    );

    expect($plan->team_id)->toBe($team->id)
        ->and($plan->starts_on->toDateString())->toBe(now()->toDateString())
        ->and($plan->conversations)->toHaveCount(1)
        ->and($plan->conversations->first()->messages)->toHaveCount(1)
        ->and($plan->conversations->first()->messages->first()->role)->toBe(MessageRole::Assistant);
});

it('stores participants and servings on an individual meal slot', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $person = $team->people()->sole();
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay());
    $slot = $plan->slots()->create([
        'team_id' => $team->id,
        'date' => today(),
        'kind' => MealSlotKind::Dinner,
    ]);

    $slot->participants()->attach($person, ['servings' => 1.5]);

    expect((float) $slot->participants()->sole()->pivot->servings)->toBe(1.5);
});

it('allows both family members to work with the same plan', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Shared family');
    app(AddUserToTeam::class)->handle($team, $member);
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDays(2));

    expect($member->can('view', $plan))->toBeTrue()
        ->and($member->can('update', $plan))->toBeTrue();
});

it('rejects plan access and creation across family boundaries', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'First family');
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay());

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Second family');

    expect($outsider->can('view', $plan))->toBeFalse();

    app(StartMealPlan::class)->handle($team, $outsider, today(), today()->addDay());
})->throws(AuthorizationException::class);

it('rejects a meal plan whose date span runs backwards', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');

    app(StartMealPlan::class)->handle($team, $user, today(), today()->subDay());
})->throws(ValidationException::class);

it('persists attributed user and assistant messages through the engine boundary', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $conversation = $plan->conversations->first();

    $engine = Mockery::mock(ChefConversationEngine::class);
    $engine->shouldReceive('respondTo')
        ->once()
        ->withArgs(fn (Conversation $sentConversation, $message) => $sentConversation->is($conversation)
            && $message->role === MessageRole::User
            && $message->user_id === $user->id)
        ->andReturn(new AssistantReply('Great — which lunches should we include?', ['invocation_id' => 'fake-invocation']));
    app()->instance(ChefConversationEngine::class, $engine);

    $assistantMessage = app(SendMessageToChef::class)->handle(
        $conversation,
        $user,
        'Please plan lunches and dinners.',
    );

    expect($conversation->messages()->count())->toBe(3)
        ->and($assistantMessage->role)->toBe(MessageRole::Assistant)
        ->and($assistantMessage->metadata)->toBe(['invocation_id' => 'fake-invocation'])
        ->and($conversation->messages()->where('role', MessageRole::User)->sole()->user_id)->toBe($user->id);
});
