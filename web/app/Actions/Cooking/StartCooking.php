<?php

namespace App\Actions\Cooking;

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealPlanMilestoneKind;
use App\Models\MealOutcome;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartCooking
{
    public function __construct(private readonly RecordMealPlanMilestone $recordMilestone) {}

    public function handle(PlannedMeal $plannedMeal, User $user): MealOutcome
    {
        if (! $user->can('update', $plannedMeal)) {
            throw new AuthorizationException('You cannot cook this meal.');
        }

        if ($plannedMeal->recipe_version_id === null || $plannedMeal->recipeVersion()->whereHas('steps')->doesntExist()) {
            throw ValidationException::withMessages(['planned_meal' => 'A usable recipe is required before cooking can start.']);
        }

        return DB::transaction(function () use ($plannedMeal, $user): MealOutcome {
            $plannedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($plannedMeal->id);
            $outcome = MealOutcome::query()->firstOrCreate(
                ['planned_meal_id' => $plannedMeal->id],
                [
                    'team_id' => $plannedMeal->team_id,
                    'meal_plan_id' => $plannedMeal->meal_plan_id,
                    'recorded_by_user_id' => $user->id,
                    'current_step_position' => 1,
                    'started_at' => now(),
                ],
            );

            if ($outcome->started_at === null && $outcome->completed_at === null) {
                $outcome->update(['recorded_by_user_id' => $user->id, 'started_at' => now()]);
            }

            $this->recordMilestone->handle($plannedMeal->mealPlan, $user, MealPlanMilestoneKind::CookingStarted);

            return $outcome->refresh();
        });
    }
}
