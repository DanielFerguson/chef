<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\Team;
use App\Models\User;

/** @return array{user: User, team: Team, plan: MealPlan, recipe: Recipe} */
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
        ->click('Conversation')
        ->assertSee('Your plan is ready to approve')
        ->pressAndWaitFor('Approve plan & prepare recipes')
        ->assertSee('Plan and recipes are ready')
        ->pressAndWaitFor('View recipes')
        ->assertSee('Chicken schnitzel')
        ->assertNoJavaScriptErrors();
});

it('uses per-person serving controls for every household participant', function () {
    $workspace = m3BrowserWorkspace();
    $primary = $workspace['team']->people()->sole();
    $tahlia = $workspace['team']->people()->create([
        'name' => 'Tahlia',
        'created_by_user_id' => $workspace['user']->id,
    ]);
    $guest = $workspace['team']->people()->create([
        'name' => 'Dinner guest',
        'created_by_user_id' => $workspace['user']->id,
    ]);
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        today(),
        MealSlotKind::Dinner,
        $workspace['team']->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Recipe,
        $workspace['recipe']->latestVersion,
    );
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->click('List')
        ->assertDontSee('Edit meal')
        ->assertNotPresent('[aria-label="Meal servings"]')
        ->assertNotPresent('[aria-label="Meal status"]')
        ->assertNotPresent('[aria-label="Meal notes"]')
        ->click('Participants and servings')
        ->assertValue("#slot-{$slot->id}-person-{$primary->id}", 1)
        ->assertValue("#slot-{$slot->id}-person-{$tahlia->id}", 1)
        ->assertValue('[aria-label="Dinner guest servings"]', 1)
        ->assertScript("() => {
            const controls = [...document.querySelectorAll(
                '[data-testid=meal-slot-{$slot->id}] [data-participant-control]',
            )];
            const rightEdges = controls.map((control) => {
                const bounds = control.getBoundingClientRect();
                return bounds.right;
            });
            return rightEdges.length === 3 && Math.max(...rightEdges) - Math.min(...rightEdges) < 1;
        }")
        ->type('[aria-label="Tahlia servings"]', '0')
        ->type('[aria-label="Dinner guest servings"]', '2')
        ->pressAndWaitFor('Save participants')
        ->assertSee('3 eating')
        ->resize(390, 844)
        ->assertPresent('[aria-label="Tahlia servings"]')
        ->assertPresent('[aria-label="Dinner guest servings"]')
        ->assertScript("() => {
            const card = document.querySelector('[data-testid=meal-slot-{$slot->id}]');
            return card.scrollWidth <= card.clientWidth;
        }")
        ->assertNoJavaScriptErrors();

    $slot->refresh()->load('participants');

    expect($slot->participants->pluck('id')->all())
        ->toContain($guest->id)
        ->not->toContain($tahlia->id)
        ->and((float) $slot->participants->firstWhere('id', $primary->id)->pivot->getAttribute('servings'))
        ->toBe(1.0)
        ->and((float) $slot->participants->firstWhere('id', $guest->id)->pivot->getAttribute('servings'))
        ->toBe(2.0);
});

it('swaps occupied meal slots through the accessible move control', function () {
    $workspace = m3BrowserWorkspace();
    $first = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today(), MealSlotKind::Dinner, $workspace['team']->people);
    $second = app(CreateMealSlot::class)->handle($workspace['plan'], $workspace['user'], today()->addDay(), MealSlotKind::Dinner, $workspace['team']->people);
    $schnitzel = app(SelectPlannedMeal::class)->handle($first, $workspace['user'], PlannedMealType::Recipe, $workspace['recipe']->latestVersion);
    $takeaway = app(SelectPlannedMeal::class)->handle($second, $workspace['user'], PlannedMealType::Takeaway, title: 'Takeaway night');
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->click('List')
        ->select('select[aria-label="Move Chicken schnitzel"]', $second->id)
        ->wait(1)
        ->assertScript("() => document.querySelector('[data-testid=meal-slot-{$first->id}]')?.textContent.includes('Takeaway night')")
        ->assertScript("() => document.querySelector('[data-testid=meal-slot-{$second->id}]')?.textContent.includes('Chicken schnitzel')")
        ->assertNoJavaScriptErrors();

    expect($schnitzel->refresh()->meal_slot_id)->toBe($second->id)
        ->and($takeaway->refresh()->meal_slot_id)->toBe($first->id);
});

it('creates a revised recipe version without changing the earlier snapshot', function () {
    $workspace = m3BrowserWorkspace();
    $firstVersionId = $workspace['recipe']->latestVersion->id;
    $this->actingAs($workspace['user']);

    visit(route('recipes.show', $workspace['recipe']))->on()->desktop()
        ->press('Revise recipe')
        ->type('[aria-label="Revised recipe title"]', 'Chicken schnitzel with slaw')
        ->type('[aria-label="Storage and leftovers guidance"]', 'Cool promptly and refrigerate for up to two days.')
        ->pressAndWaitFor('Save as new version')
        ->assertSee('v2')
        ->assertSee('Chicken schnitzel with slaw')
        ->assertSee('Cool promptly and refrigerate for up to two days.')
        ->assertNoJavaScriptErrors();

    expect($workspace['recipe']->versions()->count())->toBe(2)
        ->and($workspace['recipe']->versions()->where('version', 1)->sole()->id)->toBe($firstVersionId);
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
        ->assertCount('[data-testid="calendar-day"]', 7)
        ->assertScript("() => {
            const grid = document.querySelector('[data-testid=calendar-grid]');
            return grid.scrollWidth <= grid.clientWidth;
        }")
        ->assertPresent('article[draggable="true"]')
        ->assertDontSee('Participants and servings')
        ->click('[data-testid="meal-slot-'.$first->id.'"] [aria-label^="Open actions"]')
        ->click('Meal details and actions')
        ->assertSee('Participants and servings')
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
        ->assertScript("() => {
            const nav = document.querySelector('nav[aria-label=\"Meal plan view\"]');
            const header = nav?.closest('header');
            const title = header?.querySelector('h1');
            return Boolean(header && title)
                && title.textContent.trim() === '{$workspace['plan']->title}'
                && header.contains(nav)
                && !header.textContent.includes('Review safety')
                && document.querySelectorAll('nav[aria-label=\"Meal plan view\"]').length === 1;
        }")
        ->click('button[aria-label="Calendar"]')
        ->assertPresent('[data-testid="calendar-grid"]')
        ->assertScript("() => {
            const grid = document.querySelector('[data-testid=calendar-grid]');
            return grid.scrollWidth <= grid.clientWidth;
        }")
        ->click('button[aria-label="List"]')
        ->assertSee('Open')
        ->assertPresent('select[aria-label="Recipe"]')
        ->assertNoJavaScriptErrors();
});
