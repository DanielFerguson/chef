<?php

namespace App\Actions\Cooking;

use App\Enums\MealOutcomeStatus;
use App\Models\MealOutcome;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMealOutcome
{
    public function handle(
        PlannedMeal $plannedMeal,
        User $user,
        MealOutcomeStatus $status,
        ?string $replacementTitle = null,
        ?string $postponedUntil = null,
        ?float $leftoverServings = null,
        ?string $notes = null,
    ): MealOutcome {
        if (! $user->can('update', $plannedMeal)) {
            throw new AuthorizationException('You cannot record an outcome for this meal.');
        }

        $replacementTitle = $replacementTitle === null ? null : trim($replacementTitle);
        $notes = $notes === null ? null : trim($notes);

        if ($replacementTitle !== null && mb_strlen($replacementTitle) > 160) {
            throw ValidationException::withMessages(['replacement_title' => 'The replacement meal name is too long.']);
        }

        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw ValidationException::withMessages(['notes' => 'Outcome notes may not exceed 5,000 characters.']);
        }

        if ($status === MealOutcomeStatus::Replaced && ($replacementTitle === null || $replacementTitle === '')) {
            throw ValidationException::withMessages(['replacement_title' => 'Name the meal that replaced this one.']);
        }

        if ($status === MealOutcomeStatus::Postponed && $postponedUntil === null) {
            throw ValidationException::withMessages(['postponed_until' => 'Choose the date this meal moved to.']);
        }

        $teamToday = Date::now($plannedMeal->mealPlan->team->timezone)->startOfDay();

        if ($status === MealOutcomeStatus::Postponed && Date::parse($postponedUntil, $plannedMeal->mealPlan->team->timezone)->startOfDay()->isBefore($teamToday)) {
            throw ValidationException::withMessages(['postponed_until' => 'A postponed meal needs a current or future date.']);
        }

        if ($status === MealOutcomeStatus::Leftovers && ($leftoverServings === null || $leftoverServings <= 0)) {
            throw ValidationException::withMessages(['leftover_servings' => 'Record how many servings of leftovers remain.']);
        }

        return DB::transaction(function () use ($plannedMeal, $user, $status, $replacementTitle, $postponedUntil, $leftoverServings, $notes): MealOutcome {
            $plannedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($plannedMeal->id);
            $outcome = MealOutcome::query()->firstOrNew(['planned_meal_id' => $plannedMeal->id]);

            if ($outcome->exists
                && $outcome->feedback()->exists()
                && ! in_array($status, [MealOutcomeStatus::Cooked, MealOutcomeStatus::Leftovers], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This meal already has person feedback, so its outcome must remain cooked or cooked with leftovers.',
                ]);
            }

            $outcome->fill([
                'team_id' => $plannedMeal->team_id,
                'meal_plan_id' => $plannedMeal->meal_plan_id,
                'recorded_by_user_id' => $user->id,
                'status' => $status,
                'replacement_title' => $status === MealOutcomeStatus::Replaced ? $replacementTitle : null,
                'postponed_until' => $status === MealOutcomeStatus::Postponed ? $postponedUntil : null,
                'leftover_servings' => $status === MealOutcomeStatus::Leftovers ? $leftoverServings : null,
                'notes' => $notes,
                'completed_at' => now(),
            ]);
            $outcome->save();

            return $outcome->refresh();
        });
    }
}
