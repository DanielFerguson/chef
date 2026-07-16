<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Agents\ChefAgent;
use App\Ai\LaravelAiConversationEngine;
use App\Ai\Tools\AddPlanShoppingItem;
use App\Ai\Tools\ConfirmPlan;
use App\Ai\Tools\CorrectHouseholdPreference;
use App\Ai\Tools\CreateFamilyRecipe;
use App\Ai\Tools\CreateHouseholdPerson;
use App\Ai\Tools\CreateMealProposal;
use App\Ai\Tools\CreatePlanMealSlot;
use App\Ai\Tools\InspectMealPlan;
use App\Ai\Tools\InspectPlanShoppingList;
use App\Ai\Tools\InspectRecipes;
use App\Ai\Tools\InspectTeamContext;
use App\Ai\Tools\MoveSelectedMeal;
use App\Ai\Tools\PreparePlanShoppingList;
use App\Ai\Tools\RecordHouseholdPreference;
use App\Ai\Tools\RecordSafetyConstraint;
use App\Ai\Tools\SelectPlanMeal;
use App\Ai\Tools\SetPlanShoppingBudget;
use App\Ai\Tools\UpdatePlanDateSpan;
use App\Ai\Tools\UpdatePlanShoppingItem;
use App\Enums\MessageRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

it('uses the laravel ai sdk fake without making a live request', function () {
    ChefAgent::fake(['Let’s work out who is home on Thursday.'])->preventStrayPrompts();

    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $message = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Tahlia is away on Thursday.',
    ]);

    $reply = app(LaravelAiConversationEngine::class)->respondTo($conversation, $message);

    expect($reply->content)->toBe('Let’s work out who is home on Thursday.')
        ->and($reply->metadata)->toHaveKey('invocation_id');

    ChefAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Tahlia is away on Thursday.'));
});

it('loads durable chef messages as agent conversation context', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Make that lunches and dinners.',
    ]);

    $messages = collect((new ChefAgent($conversation, $current->id, $user, $current))->messages());

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role->value)->toBe('assistant')
        ->and($messages->first()->content)->toContain("Let's plan");
});

it('exposes only authorised first-plan domain tools to the chef agent', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $conversation = $plan->conversations->first();
    $current = $conversation->messages()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'role' => MessageRole::User,
        'content' => 'Plan our dinners and remember that we dislike olives.',
    ]);

    $tools = collect((new ChefAgent($conversation, $current->id, $user, $current))->tools());

    expect($tools->map(fn (object $tool) => $tool::class)->all())->toBe([
        InspectTeamContext::class,
        InspectMealPlan::class,
        InspectPlanShoppingList::class,
        InspectRecipes::class,
        CreateHouseholdPerson::class,
        UpdatePlanDateSpan::class,
        CreatePlanMealSlot::class,
        CreateMealProposal::class,
        CreateFamilyRecipe::class,
        SelectPlanMeal::class,
        PreparePlanShoppingList::class,
        AddPlanShoppingItem::class,
        UpdatePlanShoppingItem::class,
        SetPlanShoppingBudget::class,
        MoveSelectedMeal::class,
        RecordHouseholdPreference::class,
        CorrectHouseholdPreference::class,
        ConfirmPlan::class,
        RecordSafetyConstraint::class,
    ]);
});
