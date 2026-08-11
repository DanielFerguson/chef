<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Models\User;

it('captures an explicitly confirmed safety rule without restoring inspector chrome', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Safety family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2));
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today(),
        MealSlotKind::Dinner,
        $team->people,
    );
    app(SelectPlannedMeal::class)->handle(
        $slot,
        $user,
        PlannedMealType::Takeaway,
        title: 'Takeaway night',
    );
    $this->actingAs($user);

    $page = visit(route('meal-plans.show', $plan))
        ->resize(390, 844)
        ->assertDontSee('Household truth')
        ->assertDontSee('Plan together')
        ->assertSee('Household safety')
        ->assertSee('Confirm none reported')
        ->assertNotPresent('button[aria-label="Open plan details"]')
        ->press('Add safety rule')
        ->assertSee('Question 1 of 5')
        ->assertSee('Who does this safety rule apply to?')
        ->click('fieldset[data-active] label:has-text("Daniel")')
        ->press('Next')
        ->assertSee('What kind of rule is it?')
        ->click('fieldset[data-active] label:has-text("Allergy")')
        ->press('Next')
        ->assertSee('State the safety rule')
        ->type('input[aria-label="Safety rule subject"]', 'Peanut allergy')
        ->press('Next')
        ->assertSee('Add any important details')
        ->type('input[aria-label="Safety rule details"]', 'Avoid cross-contamination.')
        ->press('Next')
        ->assertSee('Review and explicitly confirm')
        ->assertSee('Peanut allergy')
        ->assertScript('() => document.querySelector("input[type=checkbox]")?.checked === false')
        ->press('Record rule')
        ->assertSee('Confirm the rule before recording it.');

    $this->assertDatabaseMissing('constraints', [
        'team_id' => $team->id,
        'subject' => 'Peanut allergy',
    ]);

    $page->check('input[type="checkbox"]')
        ->pressAndWaitFor('Record rule')
        ->assertSee('Peanut allergy')
        ->assertSee('I reviewed these details')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('constraints', [
        'team_id' => $team->id,
        'person_id' => $team->people()->sole()->id,
        'kind' => 'allergy',
        'subject' => 'Peanut allergy',
        'details' => 'Avoid cross-contamination.',
    ]);
});

it('discards unfinished safety capture after cancellation or reload', function () {
    $user = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($user, 'Safety family');
    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDays(2),
    );
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))
        ->press('Add safety rule')
        ->click('fieldset[data-active] label:has-text("Daniel")')
        ->press('Cancel')
        ->press('Add safety rule')
        ->assertSee('Question 1 of 5')
        ->assertScript('() => document.querySelector("fieldset[data-active] input:checked") === null')
        ->click('fieldset[data-active] label:has-text("Daniel")')
        ->press('Next')
        ->click('fieldset[data-active] label:has-text("Allergy")')
        ->press('Next')
        ->type('input[aria-label="Safety rule subject"]', 'Temporary draft');

    visit(route('meal-plans.show', $plan))
        ->press('Add safety rule')
        ->assertSee('Question 1 of 5')
        ->assertDontSee('Temporary draft')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseMissing('constraints', [
        'team_id' => $team->id,
        'subject' => 'Temporary draft',
    ]);
});
