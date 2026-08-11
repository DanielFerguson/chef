<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\MealPlanRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMealPlanRevision
{
    /** @param array<string, mixed> $changes */
    public function handle(MealPlan $mealPlan, User $user, string $summary, array $changes = [], ?int $expectedRevision = null): MealPlanRevision
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        return DB::transaction(function () use ($mealPlan, $user, $summary, $changes, $expectedRevision): MealPlanRevision {
            $locked = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);

            if ($expectedRevision !== null && $locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['expected_revision' => 'This plan changed elsewhere. Refresh it before making that change.']);
            }

            $nextRevision = $locked->revision + 1;
            $updates = ['revision' => $nextRevision];

            if ($locked->planning_confirmed_at !== null) {
                $updates['derived_data_stale_at'] = now();
                $updates['derived_data_stale_reason'] = $summary;
            }

            $locked->update($updates);

            return $locked->revisions()->create([
                'team_id' => $locked->team_id,
                'user_id' => $user->id,
                'revision' => $nextRevision,
                'summary' => $summary,
                'changes' => $changes,
            ]);
        });
    }
}
