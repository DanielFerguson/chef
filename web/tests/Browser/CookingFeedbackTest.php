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

    return compact('user', 'person', 'plan', 'meal');
}

it('takes a cook from Today through recipe steps outcome and person feedback', function () {
    $workspace = browserCookingWorkspace();
    $this->actingAs($workspace['user']);

    visit(route('dashboard'))->on()->desktop()
        ->assertSee('Lemon chicken tray bake')
        ->assertSee('Defrost the chicken first.')
        ->pressAndWaitFor('Start cooking')
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
        ->type('textarea[aria-label="Feedback notes for Daniel"]', 'Bright, easy and worth repeating.')
        ->pressAndWaitFor("Save Daniel's feedback")
        ->assertSee('Feedback saved')
        ->click('Back to Today')
        ->assertSee('cooked recorded')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('meal_feedback', [
        'person_id' => $workspace['person']->id,
        'rating' => 'like',
    ]);
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
