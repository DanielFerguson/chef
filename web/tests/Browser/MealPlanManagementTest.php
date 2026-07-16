<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('visually distinguishes the selected plan when titles are identical', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $first = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Next week');
    $second = app(StartMealPlan::class)->handle($team, $user, today()->addDays(7), today()->addDays(13), 'Next week');
    $firstHref = route('meal-plans.show', $first, absolute: false);
    $secondHref = route('meal-plans.show', $second, absolute: false);
    $this->actingAs($user);

    visit(route('meal-plans.show', $first))->on()->desktop()
        ->assertPresent("a[href=\"{$firstHref}\"][aria-current=\"page\"] [data-current-plan-indicator]")
        ->assertNotPresent("a[href=\"{$secondHref}\"] [data-current-plan-indicator]")
        ->assertAttributeMissing("a[href=\"{$secondHref}\"]", 'aria-current')
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertPresent("a[href=\"{$firstHref}\"][aria-current=\"page\"] [data-current-plan-indicator]")
        ->assertNoJavaScriptErrors();
});

it('renames and deletes the active plan from the sidebar menu', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Original plan');
    $this->actingAs($user);

    visit(route('meal-plans.show', $plan))->on()->desktop()
        ->rightClick("[data-plan-context-menu=\"{$plan->id}\"]")
        ->assertSee('Rename')
        ->assertSee('Delete')
        ->click('Rename')
        ->type("#plan-{$plan->id}-title", 'Weeknight favourites')
        ->click('Save')
        ->assertSee('Weeknight favourites')
        ->click('[aria-label="Open actions for Weeknight favourites"]')
        ->click('Delete')
        ->assertSee('This permanently deletes the plan')
        ->click('Delete plan')
        ->assertSee('Welcome to Chef')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
});

it('keeps plan actions reachable in the narrow sidebar drawer', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(2), 'Mobile plan');
    $this->actingAs($user);

    visit(route('dashboard'))
        ->resize(390, 844)
        ->click('[data-sidebar="trigger"]')
        ->assertPresent('[aria-label="Open actions for Mobile plan"]')
        ->click('[aria-label="Open actions for Mobile plan"]')
        ->assertSee('Rename')
        ->assertSee('Delete')
        ->assertNoJavaScriptErrors();
});
