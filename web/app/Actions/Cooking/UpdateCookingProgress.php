<?php

namespace App\Actions\Cooking;

use App\Models\MealOutcome;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCookingProgress
{
    public function handle(MealOutcome $outcome, User $user, int $stepPosition): MealOutcome
    {
        if (! $user->can('update', $outcome)) {
            throw new AuthorizationException('You cannot update this cooking session.');
        }

        return DB::transaction(function () use ($outcome, $user, $stepPosition): MealOutcome {
            $outcome = MealOutcome::query()->lockForUpdate()->findOrFail($outcome->id);
            $stepCount = $outcome->plannedMeal->recipeVersion?->steps()->count() ?? 0;

            if ($outcome->completed_at !== null) {
                throw ValidationException::withMessages(['step' => 'This meal has already been completed.']);
            }

            if ($stepPosition < 1 || $stepPosition > $stepCount) {
                throw ValidationException::withMessages(['step' => 'Choose a valid recipe step.']);
            }

            $outcome->update([
                'recorded_by_user_id' => $user->id,
                'current_step_position' => $stepPosition,
            ]);

            return $outcome->refresh();
        });
    }
}
