<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\MealPlans\UpdateMealPlanDateSpan;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates generated plan and conversation titles with one date-span revision', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Date family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));

    app(UpdateMealPlanDateSpan::class)->handle($plan, $user, today()->addWeek(), today()->addWeek()->addDays(6));

    expect($plan->refresh()->title)->toBe(today()->addWeek()->format('j M').' – '.today()->addWeek()->addDays(6)->format('j M Y'))
        ->and($plan->revision)->toBe(2)
        ->and($plan->conversations()->sole()->title)->toBe($plan->title)
        ->and($plan->revisions()->count())->toBe(1)
        ->and($plan->revisions()->sole()->summary)->toBe('Changed the plan date range.');
});

it('does not revise an unchanged date span', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'No-op family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));

    app(UpdateMealPlanDateSpan::class)->handle($plan, $user, today(), today()->addDays(6));

    expect($plan->refresh()->revision)->toBe(1)
        ->and($plan->revisions()->count())->toBe(0);
});

it('preserves a custom title when its dates change', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Custom title family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Quick winter dinners');

    app(UpdateMealPlanDateSpan::class)->handle($plan, $user, today()->addWeek(), today()->addWeek()->addDays(6));

    expect($plan->refresh()->title)->toBe('Quick winter dinners')
        ->and($plan->conversations()->sole()->title)->toBe('Quick winter dinners')
        ->and($plan->revision)->toBe(2);
});
