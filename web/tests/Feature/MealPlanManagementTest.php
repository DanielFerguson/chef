<?php

use App\Actions\MealPlans\RenameMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\TeamRole;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

it('renames a plan and its conversation through the shared domain action', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Original plan');

    $this->actingAs($user)
        ->from(route('meal-plans.show', $plan))
        ->put(route('meal-plans.update', $plan), [
            'title' => '  Weeknight favourites  ',
            'expected_revision' => 1,
        ])
        ->assertRedirect(route('meal-plans.show', $plan));

    expect($plan->refresh()->title)->toBe('Weeknight favourites')
        ->and($plan->revision)->toBe(2)
        ->and($plan->conversations()->sole()->title)->toBe('Weeknight favourites')
        ->and($plan->revisions()->sole()->summary)->toBe('Renamed the plan.');
});

it('does not create a revision for an unchanged plan name', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay(), 'Same plan');

    app(RenameMealPlan::class)->handle($plan, $user, 'Same plan', 1);

    expect($plan->refresh()->revision)->toBe(1)
        ->and($plan->revisions()->count())->toBe(0);
});

it('rolls a rename back when the plan revision is stale', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'The Test Kitchen');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDay(), 'Current plan');
    $plan->update(['revision' => 2]);

    expect(fn () => app(RenameMealPlan::class)->handle($plan, $user, 'Stale rename', 1))
        ->toThrow(ValidationException::class, 'This plan changed elsewhere');

    expect($plan->refresh()->title)->toBe('Current plan')
        ->and($plan->conversations()->sole()->title)->toBe('Current plan');
});

it('shares honest plan management permissions with the sidebar', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'The Test Kitchen');
    app(AddUserToTeam::class)->handle($team, $member, TeamRole::Member);
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay(), 'Family plan');

    expect($member->can('update', $plan))->toBeTrue();

    $this->withoutVite()->actingAs($member)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentMealPlans.0.title', 'Family plan')
            ->where('recentMealPlans.0.can.update', true)
            ->where('recentMealPlans.0.can.delete', false));

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentMealPlans.0.can.update', true)
            ->where('recentMealPlans.0.can.delete', true));
});

it('allows household admins to permanently delete a plan and its conversation history', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'The Test Kitchen');
    app(AddUserToTeam::class)->handle($team, $admin, TeamRole::Admin);
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay(), 'Disposable plan');
    $conversation = $plan->conversations()->sole();
    $messageId = $conversation->messages()->firstOrFail()->id;

    $this->actingAs($admin)
        ->delete(route('meal-plans.destroy', $plan), ['redirect_to_dashboard' => true])
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
    $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    $this->assertDatabaseMissing('messages', ['id' => $messageId]);
});

it('prevents household members and other teams from deleting a plan', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'The Test Kitchen');
    app(AddUserToTeam::class)->handle($team, $member, TeamRole::Member);
    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDay());

    $this->actingAs($member)
        ->delete(route('meal-plans.destroy', $plan))
        ->assertForbidden();

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Another kitchen');

    $this->actingAs($outsider)
        ->delete("/meal-plans/{$plan->id}")
        ->assertNotFound();

    expect(MealPlan::query()->find($plan->id))->not->toBeNull();
});
