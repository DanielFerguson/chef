<?php

use App\Actions\MealPlans\ApproveMealPlan;
use App\Actions\MealPlans\BuildMealPlanApprovalBrief;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\ProposeMeal;
use App\Actions\Planning\ResolveMealSlotParticipants;
use App\Actions\Planning\UpdateMealSlotParticipants;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealProposalStatus;
use App\Enums\MealSlotKind;
use App\Enums\MealSlotParticipantOrigin;
use App\Models\MealPlan;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/** @return array{user: User, team: Team, plan: MealPlan, adult: Person, child: Person} */
function frictionlessPlanWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Frictionless family');
    $adult = $team->people()->firstOrFail();
    $child = Person::factory()->for($team)->create(['name' => 'Sam']);
    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today()->next('Monday'),
        today()->next('Monday')->addDay(),
        'Easy week',
    );

    return compact('user', 'team', 'plan', 'adult', 'child');
}

it('returns structured readiness blockers and treats one filled-slot proposal as the effective draft', function () {
    $workspace = frictionlessPlanWorkspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
        [$workspace['adult']],
    );
    app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Original pasta', $slot);
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    app(ProposeMeal::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        'Lemon chicken',
        $slot,
        'A quicker replacement.',
        25,
        14,
    );

    $readiness = app(AssessMealPlanReadiness::class)->handle($workspace['plan']->refresh());

    expect($readiness['ready_for_approval'])->toBeTrue()
        ->and($readiness['ready_for_reapproval'])->toBeTrue()
        ->and($readiness['blockers'])->toBeArray()->toBeEmpty()
        ->and($readiness['next_action'])->toBe('review_and_approve');
});

it('atomically accepts pending replacements on filled slots during whole-plan reapproval', function () {
    Queue::fake();
    $workspace = frictionlessPlanWorkspace();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
        [$workspace['adult'], $workspace['child']],
    );
    $original = app(ProposeMeal::class)->handle($workspace['plan'], $workspace['user'], 'Original pasta', $slot);
    app(ApproveMealPlan::class)->handle($workspace['plan'], $workspace['user']);
    $replacement = app(ProposeMeal::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
        'Lemon chicken',
        $slot,
        'A quicker replacement.',
    );

    app(ApproveMealPlan::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($slot->plannedMeal()->sole()->title)->toBe('Lemon chicken')
        ->and($original->refresh()->status)->toBe(MealProposalStatus::Replaced)
        ->and($replacement->refresh()->status)->toBe(MealProposalStatus::Accepted)
        ->and($workspace['plan']->refresh()->derived_data_stale_at)->toBeNull();
});

it('uses the latest comparable approved meal as a visible provisional participant default', function () {
    $workspace = frictionlessPlanWorkspace();
    $historicalPlan = app(StartMealPlan::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $workspace['plan']->starts_on->subWeek(),
        $workspace['plan']->starts_on->subWeek(),
        'Last week',
    );
    $historicalSlot = app(CreateMealSlot::class)->handle(
        $historicalPlan,
        $workspace['user'],
        $historicalPlan->starts_on,
        MealSlotKind::Dinner,
        [$workspace['adult'], $workspace['child']],
        servingsByPerson: [
            $workspace['adult']->id => 1.5,
            $workspace['child']->id => 0.5,
        ],
    );
    app(ProposeMeal::class)->handle($historicalPlan, $workspace['user'], 'Monday dinner', $historicalSlot);
    app(ApproveMealPlan::class)->handle($historicalPlan, $workspace['user']);

    $assignment = app(ResolveMealSlotParticipants::class)->handle(
        $workspace['plan'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
    );

    expect($assignment['origin'])->toBe(MealSlotParticipantOrigin::ProvisionalHistory)
        ->and($assignment['source_meal_slot_id'])->toBe($historicalSlot->id)
        ->and($assignment['servings_by_person'])->toBe([
            $workspace['adult']->id => 1.5,
            $workspace['child']->id => 0.5,
        ]);
});

it('falls back to the current household and marks a manual correction explicit', function () {
    $workspace = frictionlessPlanWorkspace();
    $assignment = app(ResolveMealSlotParticipants::class)->handle(
        $workspace['plan'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
    );
    $people = Person::query()->whereKey(array_keys($assignment['servings_by_person']))->get();
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
        $people,
        servingsByPerson: $assignment['servings_by_person'],
        participantOrigin: $assignment['origin'],
    );

    expect($slot->participant_assignment_origin)->toBe(MealSlotParticipantOrigin::FallbackHousehold)
        ->and($slot->participants->pluck('pivot.servings', 'id')->map(fn ($value) => (float) $value)->all())
        ->toBe([
            $workspace['adult']->id => 1.0,
            $workspace['child']->id => 1.0,
        ]);

    app(UpdateMealSlotParticipants::class)->handle(
        $slot,
        $workspace['user'],
        [$workspace['adult']->id => 2],
    );

    expect($slot->refresh()->participant_assignment_origin)->toBe(MealSlotParticipantOrigin::Explicit)
        ->and($slot->participant_source_meal_slot_id)->toBeNull();
});

it('builds one authoritative approval brief with the effective replacement and participant provenance', function () {
    $workspace = frictionlessPlanWorkspace();
    $assignment = app(ResolveMealSlotParticipants::class)->handle(
        $workspace['plan'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
    );
    $slot = app(CreateMealSlot::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        $workspace['plan']->starts_on,
        MealSlotKind::Dinner,
        Person::query()->whereKey(array_keys($assignment['servings_by_person']))->get(),
        servingsByPerson: $assignment['servings_by_person'],
        participantOrigin: $assignment['origin'],
    );
    app(ProposeMeal::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        'Tray-bake chicken',
        $slot,
        'An easy family dinner.',
        35,
        18,
    );

    $brief = app(BuildMealPlanApprovalBrief::class)->handle(
        $workspace['plan']->refresh(),
        $workspace['user'],
    );

    expect($brief['meal_count'])->toBe(1)
        ->and($brief['estimated_minutes'])->toBe(35)
        ->and($brief['estimated_cost_cents'])->toBe(1800)
        ->and($brief['meals'][0]['title'])->toBe('Tray-bake chicken')
        ->and($brief['meals'][0]['is_replacement'])->toBeFalse()
        ->and($brief['meals'][0]['participant_default']['origin'])->toBe('fallback_household')
        ->and($brief['meals'][0]['participants'])->toHaveCount(2)
        ->and($brief['safety']['inferred'])->toBeFalse()
        ->and($brief['purchase_policy']['home_brand_preference'])->toBe('allow')
        ->and($brief['purchase_policy']['bulk_preference'])->toBe('avoid')
        ->and($brief['purchase_policy']['basket_target_cents'])->toBeNull();
});
