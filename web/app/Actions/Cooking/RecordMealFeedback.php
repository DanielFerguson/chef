<?php

namespace App\Actions\Cooking;

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealFeedbackRating;
use App\Enums\MealOutcomeStatus;
use App\Enums\MealPlanMilestoneKind;
use App\Models\MealFeedback;
use App\Models\MealOutcome;
use App\Models\Person;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMealFeedback
{
    public function __construct(
        private readonly DerivePreferenceCandidates $deriveCandidates,
        private readonly RecordMealPlanMilestone $recordMilestone,
    ) {}

    public function handle(
        MealOutcome $outcome,
        Person $person,
        User $user,
        MealFeedbackRating $rating,
        ?string $portion = null,
        ?string $effort = null,
        ?string $cost = null,
        ?string $leftovers = null,
        ?string $notes = null,
        ?string $recipeAdjustment = null,
    ): MealFeedback {
        if (! $user->can('update', $outcome) || $person->team_id !== $outcome->team_id) {
            throw new AuthorizationException('You cannot record feedback for this meal.');
        }

        if ($outcome->completed_at === null) {
            throw ValidationException::withMessages(['outcome' => 'Record what happened to the meal before adding feedback.']);
        }

        if (! in_array($outcome->status, [MealOutcomeStatus::Cooked, MealOutcomeStatus::Leftovers], true)) {
            throw ValidationException::withMessages(['outcome' => 'Person feedback is only available for a meal that was cooked.']);
        }

        if (! $outcome->plannedMeal->mealSlot->participants()->whereKey($person->id)->exists()) {
            throw ValidationException::withMessages(['person' => 'Feedback can only be recorded for someone who participated in this meal.']);
        }

        foreach ([
            'portion' => [$portion, ['too_small', 'right', 'too_large']],
            'effort' => [$effort, ['easy', 'right', 'too_much']],
            'cost' => [$cost, ['good_value', 'right', 'too_high']],
            'leftovers' => [$leftovers, ['none', 'some', 'plenty']],
        ] as $field => [$value, $allowed]) {
            if ($value !== null && ! in_array($value, $allowed, true)) {
                throw ValidationException::withMessages([$field => 'Choose a valid '.$field.' response.']);
            }
        }

        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw ValidationException::withMessages(['notes' => 'Feedback notes may not exceed 5,000 characters.']);
        }

        if ($recipeAdjustment !== null && mb_strlen($recipeAdjustment) > 5000) {
            throw ValidationException::withMessages(['recipe_adjustment' => 'Recipe adjustments may not exceed 5,000 characters.']);
        }

        $feedback = DB::transaction(function () use ($outcome, $person, $user, $rating, $portion, $effort, $cost, $leftovers, $notes, $recipeAdjustment): MealFeedback {
            $outcome = MealOutcome::query()->lockForUpdate()->findOrFail($outcome->id);

            return MealFeedback::query()->updateOrCreate(
                ['meal_outcome_id' => $outcome->id, 'person_id' => $person->id],
                [
                    'team_id' => $outcome->team_id,
                    'recorded_by_user_id' => $user->id,
                    'rating' => $rating,
                    'portion' => $portion,
                    'effort' => $effort,
                    'cost' => $cost,
                    'leftovers' => $leftovers,
                    'notes' => $notes === null ? null : trim($notes),
                    'recipe_adjustment' => $recipeAdjustment === null ? null : trim($recipeAdjustment),
                    'submitted_at' => now(),
                ],
            );
        });

        $plannedMeal = $outcome->plannedMeal()->with('recipeVersion.recipe')->firstOrFail();
        $this->deriveCandidates->handle($person, $plannedMeal->recipeVersion?->recipe, $plannedMeal->title);
        $this->recordMilestone->handle($outcome->mealPlan, $user, MealPlanMilestoneKind::ReviewCompleted);

        return $feedback;
    }
}
