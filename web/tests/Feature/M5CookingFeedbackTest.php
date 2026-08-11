<?php

use App\Actions\Cooking\RecordMealFeedback;
use App\Actions\Cooking\RecordMealOutcome;
use App\Actions\Cooking\ReviewPreferenceCandidate;
use App\Actions\Cooking\StartCooking;
use App\Actions\Cooking\UpdateCookingProgress;
use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\BuildRecommendationExplanation;
use App\Actions\Planning\CreateMealSlot;
use App\Actions\Planning\SelectPlannedMeal;
use App\Actions\Recipes\CreateRecipe;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\MealFeedbackRating;
use App\Enums\MealOutcomeStatus;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\MealSlotKind;
use App\Enums\PlannedMealType;
use App\Enums\PreferenceCandidateStatus;
use App\Enums\PreferenceProvenance;
use App\Models\MealOutcome;
use App\Models\MealPlan;
use App\Models\MealSlot;
use App\Models\Person;
use App\Models\PlannedMeal;
use App\Models\Preference;
use App\Models\PreferenceCandidate;
use App\Models\Recipe;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     team: Team,
 *     person: Person,
 *     secondPerson: Person,
 *     plan: MealPlan,
 *     recipe: Recipe,
 *     slot: MealSlot,
 *     meal: PlannedMeal
 * }
 */
function m5CookingWorkspace(int $dayOffset = 0): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'M5 family');
    $person = $team->people()->sole();
    $secondPerson = $team->people()->create(['name' => 'Tahlia', 'created_by_user_id' => $user->id]);
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6), 'Cooking week');
    $recipe = app(CreateRecipe::class)->handle(
        $team,
        $user,
        'Lemon chicken tray bake',
        'A relaxed family dinner.',
        2,
        15,
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
        'Store leftovers chilled for up to two days.',
    );
    $slot = app(CreateMealSlot::class)->handle(
        $plan,
        $user,
        today()->addDays($dayOffset),
        MealSlotKind::Dinner,
        collect([$person, $secondPerson]),
    );
    $meal = app(SelectPlannedMeal::class)->handle($slot, $user, PlannedMealType::Recipe, $recipe->latestVersion);
    app(ReviewMealPlanSafety::class)->handle($plan->refresh(), $user);
    app(ConfirmMealPlan::class)->handle($plan->refresh(), $user);

    return compact('user', 'team', 'person', 'secondPerson', 'plan', 'recipe', 'slot', 'meal');
}

it('starts and resumes one durable cooking session while recording the plan milestone', function () {
    $workspace = m5CookingWorkspace();
    $first = app(StartCooking::class)->handle($workspace['meal'], $workspace['user']);
    $second = app(StartCooking::class)->handle($workspace['meal'], $workspace['user']);

    expect($second->is($first))->toBeTrue()
        ->and($first->started_at)->not->toBeNull()
        ->and($first->current_step_position)->toBe(1)
        ->and(MealOutcome::query()->count())->toBe(1)
        ->and($workspace['plan']->milestones()->where('kind', MealPlanMilestoneKind::CookingStarted)->count())->toBe(1);

    app(UpdateCookingProgress::class)->handle($first, $workspace['user'], 2);

    expect($first->refresh()->current_step_position)->toBe(2);
});

it('rejects invalid progress and cross-family cooking access', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(StartCooking::class)->handle($workspace['meal'], $workspace['user']);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect(fn () => app(UpdateCookingProgress::class)->handle($outcome, $workspace['user'], 3))
        ->toThrow(ValidationException::class, 'Choose a valid recipe step');
    expect(fn () => app(StartCooking::class)->handle($workspace['meal'], $outsider))
        ->toThrow(AuthorizationException::class);
});

it('records every supported meal outcome with status-specific context', function (MealOutcomeStatus $status, array $context) {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle(
        $workspace['meal'],
        $workspace['user'],
        $status,
        $context['replacement_title'] ?? null,
        $context['postponed_until'] ?? null,
        $context['leftover_servings'] ?? null,
        'Recorded from Today.',
    );

    expect($outcome->status)->toBe($status)
        ->and($outcome->completed_at)->not->toBeNull()
        ->and($outcome->notes)->toBe('Recorded from Today.');
})->with([
    'cooked' => [MealOutcomeStatus::Cooked, []],
    'skipped' => [MealOutcomeStatus::Skipped, []],
    'postponed' => [MealOutcomeStatus::Postponed, ['postponed_until' => today()->addDay()->toDateString()]],
    'replaced' => [MealOutcomeStatus::Replaced, ['replacement_title' => 'Soup']],
    'leftovers' => [MealOutcomeStatus::Leftovers, ['leftover_servings' => 2.0]],
    'ate out' => [MealOutcomeStatus::AteOut, []],
]);

it('requires context for postponed replaced and leftover outcomes', function () {
    $workspace = m5CookingWorkspace();

    expect(fn () => app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Replaced))
        ->toThrow(ValidationException::class, 'Name the meal');
    expect(fn () => app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Postponed))
        ->toThrow(ValidationException::class, 'Choose the date');
    expect(fn () => app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Leftovers))
        ->toThrow(ValidationException::class, 'Record how many servings');
});

it('records participant-specific feedback and safely updates the same response', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Cooked);
    $feedback = app(RecordMealFeedback::class)->handle(
        $outcome,
        $workspace['person'],
        $workspace['user'],
        MealFeedbackRating::Like,
        portion: 'right',
        effort: 'easy',
        notes: 'The lemon worked well.',
        recipeAdjustment: 'Add more herbs.',
    );
    $updated = app(RecordMealFeedback::class)->handle(
        $outcome,
        $workspace['person'],
        $workspace['user'],
        MealFeedbackRating::Favourite,
        portion: 'too_small',
    );

    expect($updated->is($feedback))->toBeTrue()
        ->and($updated->rating)->toBe(MealFeedbackRating::Favourite)
        ->and($updated->portion)->toBe('too_small')
        ->and($outcome->feedback()->count())->toBe(1)
        ->and($workspace['plan']->milestones()->where('kind', MealPlanMilestoneKind::ReviewCompleted)->exists())->toBeTrue();

    $nonParticipant = $workspace['team']->people()->create(['name' => 'Guest']);
    app(RecordMealFeedback::class)->handle($outcome, $nonParticipant, $workspace['user'], MealFeedbackRating::Like);
})->throws(ValidationException::class, 'only be recorded for someone who participated');

it('does not attach recipe feedback to a meal that was not cooked', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Skipped);

    app(RecordMealFeedback::class)->handle(
        $outcome,
        $workspace['person'],
        $workspace['user'],
        MealFeedbackRating::Like,
    );
})->throws(ValidationException::class, 'only available for a meal that was cooked');

it('does not let an outcome correction orphan existing person feedback', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Cooked);
    app(RecordMealFeedback::class)->handle($outcome, $workspace['person'], $workspace['user'], MealFeedbackRating::Like);

    app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Skipped);
})->throws(ValidationException::class, 'must remain cooked');

it('enforces feedback dimensions inside the reusable action', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Cooked);

    app(RecordMealFeedback::class)->handle(
        $outcome,
        $workspace['person'],
        $workspace['user'],
        MealFeedbackRating::Like,
        portion: 'enormous',
    );
})->throws(ValidationException::class, 'valid portion response');

it('derives an inspectable candidate from repeated feedback without creating a safety rule', function () {
    $first = m5CookingWorkspace();
    $secondSlot = app(CreateMealSlot::class)->handle(
        $first['plan'],
        $first['user'],
        today()->addDay(),
        MealSlotKind::Dinner,
        collect([$first['person']]),
    );
    $secondMeal = app(SelectPlannedMeal::class)->handle($secondSlot, $first['user'], PlannedMealType::Recipe, $first['recipe']->latestVersion);

    foreach ([$first['meal'], $secondMeal] as $meal) {
        $outcome = app(RecordMealOutcome::class)->handle($meal, $first['user'], MealOutcomeStatus::Cooked);
        app(RecordMealFeedback::class)->handle($outcome, $first['person'], $first['user'], MealFeedbackRating::Like);
    }

    $candidate = PreferenceCandidate::query()->sole();
    $explanation = app(BuildRecommendationExplanation::class)->handle($first['plan'], $first['recipe']->latestVersion);

    expect($candidate->status)->toBe(PreferenceCandidateStatus::Pending)
        ->and($candidate->evidence_count)->toBe(2)
        ->and($candidate->sentiment->value)->toBe('like')
        ->and($first['team']->constraints()->count())->toBe(0)
        ->and(Preference::query()->count())->toBe(0)
        ->and($explanation['feedback'][0]['explanation'])->toContain('rated this meal positively across 2 recorded meals')
        ->and($explanation['feedback'][0]['status'])->toBe('pending');

    app(RecordMealFeedback::class)->handle(
        $secondMeal->outcome,
        $first['person'],
        $first['user'],
        MealFeedbackRating::Neutral,
    );

    expect(PreferenceCandidate::query()->count())->toBe(0);
});

it('accepts a candidate as an ordinary feedback preference and keeps review decisions one-way', function () {
    $workspace = m5CookingWorkspace();
    $candidate = PreferenceCandidate::query()->create([
        'team_id' => $workspace['team']->id,
        'person_id' => $workspace['person']->id,
        'recipe_id' => $workspace['recipe']->id,
        'identity_key' => hash('sha256', 'candidate'),
        'subject' => $workspace['recipe']->title,
        'normalized_subject' => mb_strtolower($workspace['recipe']->title),
        'sentiment' => 'like',
        'evidence_count' => 2,
        'confidence' => 0.8,
        'evidence' => ['meal_feedback_ids' => [10, 11]],
        'status' => 'pending',
    ]);
    $reviewed = app(ReviewPreferenceCandidate::class)->handle($candidate, $workspace['user'], PreferenceCandidateStatus::Accepted);
    $preference = $reviewed->preference;

    expect($preference)->not->toBeNull()
        ->and($preference->provenance)->toBe(PreferenceProvenance::Feedback)
        ->and($preference->evidence['preference_candidate_id'])->toBe($candidate->id)
        ->and($workspace['team']->constraints()->count())->toBe(0);

    app(ReviewPreferenceCandidate::class)->handle($reviewed, $workspace['user'], PreferenceCandidateStatus::Dismissed);
})->throws(ValidationException::class, 'already been reviewed');

it('does not accept a candidate over a conflicting explicit preference', function () {
    $workspace = m5CookingWorkspace();
    Preference::query()->create([
        'team_id' => $workspace['team']->id,
        'person_id' => $workspace['person']->id,
        'subject' => $workspace['recipe']->title,
        'sentiment' => 'dislike',
        'strength' => 5,
        'provenance' => 'stated',
    ]);
    $candidate = PreferenceCandidate::query()->create([
        'team_id' => $workspace['team']->id,
        'person_id' => $workspace['person']->id,
        'recipe_id' => $workspace['recipe']->id,
        'identity_key' => hash('sha256', 'conflicting-candidate'),
        'subject' => $workspace['recipe']->title,
        'normalized_subject' => mb_strtolower($workspace['recipe']->title),
        'sentiment' => 'like',
        'evidence_count' => 2,
        'confidence' => 0.8,
        'evidence' => [],
        'status' => 'pending',
    ]);

    app(ReviewPreferenceCandidate::class)->handle($candidate, $workspace['user'], PreferenceCandidateStatus::Accepted);
})->throws(ValidationException::class, 'conflicting preference');

it('keeps dismissed feedback patterns out of later recommendation explanations', function () {
    $workspace = m5CookingWorkspace();
    PreferenceCandidate::query()->create([
        'team_id' => $workspace['team']->id,
        'person_id' => $workspace['person']->id,
        'recipe_id' => $workspace['recipe']->id,
        'reviewed_by_user_id' => $workspace['user']->id,
        'identity_key' => hash('sha256', 'dismissed-candidate'),
        'subject' => $workspace['recipe']->title,
        'normalized_subject' => mb_strtolower($workspace['recipe']->title),
        'sentiment' => 'like',
        'evidence_count' => 2,
        'confidence' => 0.8,
        'evidence' => [],
        'status' => 'dismissed',
        'reviewed_at' => now(),
    ]);

    $explanation = app(BuildRecommendationExplanation::class)->handle($workspace['plan'], $workspace['recipe']->latestVersion);

    expect($explanation['feedback'])->toBe([]);
});

it('serves the Today and cooking surfaces with recipe truth and team-scoped bindings', function () {
    $workspace = m5CookingWorkspace();
    $this->withoutVite()->actingAs($workspace['user'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('today.meals.0.title', 'Lemon chicken tray bake')
            ->where('today.meals.0.recipe.total_minutes', 45)
            ->where('today.meals.0.recipe.preparation_notices.0.instruction', 'Defrost the chicken first.'));
    $this->get(route('planned-meals.cook.show', $workspace['meal']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('cooking/show')
            ->where('meal.recipe_version.ingredients.0.name', 'Chicken thigh')
            ->where('meal.recipe_version.equipment.0.name', 'Roasting tray')
            ->where('meal.recipe_version.steps.1.timer_minutes', 30));

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    $this->actingAs($outsider)->get(route('planned-meals.cook.show', $workspace['meal']))->assertNotFound();
});

it('falls forward to the next planned date when today is empty', function () {
    $workspace = m5CookingWorkspace();
    $workspace['plan']->slots()->update(['date' => today()->addDays(6)]);

    $this->withoutVite()->actingAs($workspace['user'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('today.showing_next', true)
            ->where('today.meals.0.title', 'Lemon chicken tray bake')
            ->where('today.meals.0.date', today()->addDays(6)->toDateString()));
});

it('protects every M5 root record with family policies', function () {
    $workspace = m5CookingWorkspace();
    $outcome = app(RecordMealOutcome::class)->handle($workspace['meal'], $workspace['user'], MealOutcomeStatus::Cooked);
    $feedback = app(RecordMealFeedback::class)->handle($outcome, $workspace['person'], $workspace['user'], MealFeedbackRating::Neutral);
    $candidate = PreferenceCandidate::query()->create([
        'team_id' => $workspace['team']->id,
        'person_id' => $workspace['person']->id,
        'identity_key' => hash('sha256', 'policy-candidate'),
        'subject' => 'Tray bake',
        'normalized_subject' => 'tray bake',
        'sentiment' => 'like',
        'evidence_count' => 2,
        'confidence' => 0.8,
        'evidence' => [],
    ]);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    expect($workspace['user']->can('view', $outcome))->toBeTrue()
        ->and($workspace['user']->can('update', $feedback))->toBeTrue()
        ->and($workspace['user']->can('update', $candidate))->toBeTrue()
        ->and($outsider->can('view', $outcome))->toBeFalse()
        ->and($outsider->can('update', $feedback))->toBeFalse()
        ->and($outsider->can('update', $candidate))->toBeFalse();
});
