<?php

use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Shopping\GenerateShoppingList;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function browserShoppingWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Shopping family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Satay chicken',
        'A quick dinner.',
        2,
        10,
        20,
        [
            ['name' => 'Chicken breast', 'quantity' => 500, 'unit' => 'g'],
            ['name' => 'Coconut milk', 'quantity' => 400, 'unit' => 'ml'],
        ],
        [['instruction' => 'Cook everything together.']],
    );
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, $team->people);
    app(SelectPlannedMeal::class)->handle($slot, $user, PlannedMealType::Recipe, $recipe->latestVersion, servings: 2);
    app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);

    return compact('user', 'plan');
}

it('takes a confirmed plan through an editable traceable shopping list', function () {
    $workspace = browserShoppingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->pressAndWaitFor('Start shopping list')
        ->assertSee('Shopping list')
        ->assertSee('For Satay chicken')
        ->assertPresent('input[aria-label="Chicken breast name"]')
        ->assertPresent('input[aria-label="Coconut milk name"]')
        ->type('input[aria-label="Shopping budget"]', '100')
        ->pressAndWaitFor('Save budget')
        ->assertSee('$100.00')
        ->press('Match retailer product')
        ->type('input[aria-label="Product name for Chicken breast"]', 'Coles Chicken Breast')
        ->type('input[aria-label="Unit price for Chicken breast"]', '12.50')
        ->pressAndWaitFor('Save product match')
        ->assertSee('Coles Chicken Breast')
        ->type('input[aria-label="New shopping item"]', 'Hand soap')
        ->type('input[aria-label="New item quantity"]', '1')
        ->pressAndWaitFor('Add')
        ->assertPresent('input[aria-label="Hand soap name"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Chicken breast as bought"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Coconut milk as bought"]')
        ->click('[data-slot="checkbox"][aria-label="Mark Hand soap as bought"]')
        ->pressAndWaitFor('Complete shop')
        ->assertSee('Completed')
        ->type('input[aria-label="Actual order total"]', '31.40')
        ->pressAndWaitFor('Record order')
        ->assertSee('$31.40')
        ->assertNoJavaScriptErrors();
});

it('resolves ingredients for a custom meal in the shopping workspace', function () {
    $workspace = browserShoppingWorkspace();
    $team = $workspace['user']->currentTeam;
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        today(),
        MealSlotKind::Lunch,
        $team->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $workspace['user'],
        PlannedMealType::Custom,
        title: 'Pulled pork rolls',
        servings: 2,
    );
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('1 meal needs structured ingredients')
        ->type('textarea[aria-label="Ingredients for Pulled pork rolls"]', "Bread rolls | 4 | each\nColeslaw | 1 | bag")
        ->pressAndWaitFor('Add meal ingredients')
        ->assertDontSee('meal needs structured ingredients')
        ->assertPresent('input[aria-label="Bread rolls name"]')
        ->assertSee('For Pulled pork rolls')
        ->assertNoJavaScriptErrors();
});

it('keeps the shopping editor usable at a narrow viewport', function () {
    $workspace = browserShoppingWorkspace();
    app(GenerateShoppingList::class)->handle($workspace['plan']->refresh(), $workspace['user']);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Shopping list')
        ->assertSee('For Satay chicken')
        ->assertPresent('input[aria-label="New shopping item"]')
        ->assertPresent('button[aria-label="Actions for Chicken breast"]')
        ->assertNoJavaScriptErrors();
});
