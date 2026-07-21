<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('captures response feedback and carries a completed plan into confirmation feedback', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Feedback family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people()->get(),
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Custom,
        title: 'Chicken katsu curry with rice',
        servings: 2,
    );
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->assertSee('Review this plan’s safety details')
        ->pressAndWaitFor('Confirm none reported')
        ->assertSee('Your plan is ready to confirm')
        ->click('button[aria-label="Mark this response unhelpful"]')
        ->assertSee('What could Chef improve?')
        ->click('Changed the wrong thing')
        ->type('textarea[placeholder="Tell us what happened…"]', 'Chef assigned this to the wrong person.')
        ->pressAndWaitFor('Save feedback')
        ->click('Review and confirm')
        ->assertSee('Confirmed')
        ->assertSee('Planning is complete')
        ->assertSee('Shopping is next.')
        ->assertSee('Start shopping list')
        ->assertSee('How did planning feel?')
        ->click('button[aria-label="Planning was helpful"]')
        ->assertSee('What worked well?')
        ->type('textarea[placeholder="Tell us what happened…"]', 'The plan understood our preferences.')
        ->pressAndWaitFor('Save feedback')
        ->assertSee('Thanks — feedback saved.')
        ->pressAndWaitFor('Start shopping list')
        ->assertSee('Shopping list')
        ->assertDontSee('needs structured ingredients')
        ->click('button[aria-label="Edit Chicken katsu curry with rice ingredients"]')
        ->assertPresent('input[aria-label="Chicken katsu curry with rice ingredients name"]')
        ->assertPresent('input[aria-label="New shopping item"]')
        ->assertNoJavaScriptErrors();

    $feedback = $plan->conversations->firstOrFail()->feedback()->whereNotNull('message_id')->sole();
    expect($feedback->rating->value)->toBe('unhelpful')
        ->and($feedback->reasons)->toBe(['wrong_action'])
        ->and($feedback->comment)->toBe('Chef assigned this to the wrong person.');

    $checkpoint = $plan->conversations->firstOrFail()->feedback()->whereNull('message_id')->sole();
    expect($checkpoint->rating->value)->toBe('helpful')
        ->and($checkpoint->comment)->toBe('The plan understood our preferences.');
});
