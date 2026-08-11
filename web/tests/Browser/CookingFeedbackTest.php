<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\PlannedMeal;
use App\Models\User;

/** @return array{user: User, person: Person, plan: MealPlan, meal: PlannedMeal} */
function browserCookingWorkspace(): array
{
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Cooking family');
    $person = $team->people()->sole();
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2), 'Dinner plan');
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Lemon chicken tray bake',
        'A relaxed family dinner.',
        2,
        10,
        30,
        [
            ['name' => 'Chicken thigh', 'quantity' => 500, 'unit' => 'g'],
            ['name' => 'Lemon', 'quantity' => 1, 'unit' => 'whole'],
        ],
        [
            ['instruction' => 'Heat the oven and prepare the tray.'],
            ['instruction' => 'Roast until golden.', 'timer_minutes' => 30],
        ],
        ['Roasting tray'],
        [['kind' => 'advance_prep', 'instruction' => 'Defrost the chicken first.', 'lead_minutes' => 240]],
    );
    $slot = app(CreateMealSlot::class)->handle($plan, $user, today(), MealSlotKind::Dinner, collect([$person]));
    $meal = app(SelectPlannedMeal::class)->handle($slot, $user, PlannedMealType::Recipe, $recipe->latestVersion);
    app(ApproveMealPlan::class)->handle($plan, $user);

    return compact('user', 'person', 'plan', 'meal');
}

it('takes a cook from Today through recipe steps outcome and person feedback', function () {
    $workspace = browserCookingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('dashboard'))->on()->desktop()
        ->assertSee('Lemon chicken tray bake')
        ->assertSee('Defrost the chicken first.')
        ->click('Start cooking')
        ->assertSee('Step 1 of 2')
        ->assertSee('Heat the oven and prepare the tray.')
        ->click('Next')
        ->assertSee('Step 2 of 2')
        ->assertSee('Roast until golden.')
        ->assertSee('30 minute timer')
        ->click('Start')
        ->assertSee('Running')
        ->click('Previous')
        ->click('Next')
        ->assertSee('Running')
        ->click('Finish and record outcome')
        ->assertSee('What happened with this meal?')
        ->pressAndWaitFor('Save outcome')
        ->assertSee('How was it for everyone?')
        ->click('button[aria-label="like for Daniel"]')
        ->assertSee('Question 1 of 5')
        ->assertSee('How was the portion?')
        ->click('fieldset[data-active] label:has-text("About right")')
        ->press('Next')
        ->assertSee('How did the cooking effort feel?')
        ->press('Skip')
        ->assertSee('How did the cost feel?')
        ->click('fieldset[data-active] label:has-text("Good value")')
        ->press('Next')
        ->assertSee('How much was left?')
        ->click('fieldset[data-active] label:has-text("None left")')
        ->press('Next')
        ->assertSee('Anything else worth remembering?')
        ->type('textarea[aria-label="Feedback notes for Daniel"]', 'Bright, easy and worth repeating.')
        ->type('input[aria-label="Recipe adjustment for Daniel"]', 'Add more lemon next time.')
        ->press('Save details')
        ->assertSee('Feedback saved')
        ->press('Add or edit details')
        ->assertSee('Question 5 of 5')
        ->press('Finish later')
        ->assertDontSee('Question 5 of 5')
        ->click('button[aria-label="dislike for Daniel"]')
        ->assertSee('How was the portion?')
        ->press('Finish later')
        ->click('Back to Today')
        ->assertSee('cooked recorded')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('meal_feedback', [
        'person_id' => $workspace['person']->id,
        'rating' => 'dislike',
        'portion' => 'right',
        'effort' => null,
        'cost' => 'good_value',
        'leftovers' => 'none',
        'notes' => 'Bright, easy and worth repeating.',
        'recipe_adjustment' => 'Add more lemon next time.',
    ]);
});

it('saves a quick rating before optional cooking details are finished', function () {
    $workspace = browserCookingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('planned-meals.cook.show', $workspace['meal']))
        ->resize(390, 844)
        ->pressAndWaitFor('Start cooking')
        ->click('Next')
        ->click('Finish and record outcome')
        ->pressAndWaitFor('Save outcome')
        ->click('button[aria-label="favourite for Daniel"]')
        ->assertSee('How was the portion?')
        ->press('Finish later')
        ->assertDontSee('How was the portion?')
        ->assertPresent('button:has-text("Add or edit details")')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('meal_feedback', [
        'person_id' => $workspace['person']->id,
        'rating' => 'favourite',
        'portion' => null,
        'notes' => null,
    ]);

    visit(route('planned-meals.cook.show', $workspace['meal']))
        ->assertSee('Feedback saved')
        ->assertDontSee('How was the portion?')
        ->assertNoJavaScriptErrors();
});

it('keeps Today and cooking mode usable at 390 by 844', function () {
    $workspace = browserCookingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('dashboard'))
        ->resize(390, 844)
        ->assertSee('Lemon chicken tray bake')
        ->pressAndWaitFor('Start cooking')
        ->assertSee('Step 1 of 2')
        ->assertPresent('button[aria-label="Toggle distraction-free fullscreen"]')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});
