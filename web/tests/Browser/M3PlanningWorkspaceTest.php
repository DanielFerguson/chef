<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function m3BrowserWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Browser family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Chicken schnitzel',
        'Air-fried with roast potatoes.',
        2,
        15,
        20,
        [['name' => 'Chicken breast'], ['name' => 'Panko crumbs']],
        [['instruction' => 'Crumb the chicken.'], ['instruction' => 'Air fry until golden.']],
    );

    return compact('user', 'team', 'plan', 'recipe');
}

it('imports and reads a structured recipe in a real browser', function () {
    $workspace = m3BrowserWorkspace();
    $this->actingAs($workspace['user']);

    visit('/recipes')->on()->desktop()
        ->assertSee('Chicken schnitzel')
        ->click('Import')
        ->type('textarea[aria-label="Recipe text to import"]', "Pork Katsu\n\nIngredients\n- Pork loin\n- Panko\n\nSteps\n1. Crumb the pork.\n2. Air fry until golden.")
        ->click('Import recipe')
        ->assertSee('Pork Katsu')
        ->assertSee('Ingredients')
        ->assertSee('Pork loin')
        ->assertSee('Method')
        ->assertNoJavaScriptErrors();
});

it('selects and confirms a recipe from the complete planning workspace', function () {
    $workspace = m3BrowserWorkspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->click('List')
        ->assertSee('Open')
        ->select('select[aria-label="Recipe"]', $workspace['recipe']->latestVersion->id)
        ->pressAndWaitFor('Select meal')
        ->assertSee('Chicken schnitzel')
        ->assertSee('Why this fits')
        ->click('Confirm plan')
        ->assertSee('Confirmed')
        ->assertNoJavaScriptErrors();
});

it('shows drag scheduling and completes the accessible move alternative without losing recipe versions', function () {
    $workspace = m3BrowserWorkspace();
    $first = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $second = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today()->addDay(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $planned = app(SelectPlannedMeal::class)->handle(
        $first,
        $workspace['user'],
        PlannedMealType::Recipe,
        $workspace['recipe']->latestVersion,
    );
    $versionId = $planned->recipe_version_id;
    $this->actingAs($workspace['user']);

    $page = visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->click('nav[aria-label="Meal plan view"] button:nth-child(2)')
        ->assertPresent('article[draggable="true"]')
        ->select('select[aria-label^="Move "]', $second->id)
        ->wait(2);

    expect($planned->refresh()->meal_slot_id)->toBe($second->id)
        ->and($planned->recipe_version_id)->toBe($versionId);

    $page->click('nav[aria-label="Meal plan view"] button:nth-child(3)')
        ->assertPresent('select[aria-label^="Move "]')
        ->assertNoJavaScriptErrors();
});

it('keeps recipes and planning controls usable at 390 by 844', function () {
    $workspace = m3BrowserWorkspace();
    app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Conversation')
        ->click('List')
        ->assertSee('Open')
        ->assertPresent('select[aria-label="Recipe"]')
        ->assertNoJavaScriptErrors();
});
