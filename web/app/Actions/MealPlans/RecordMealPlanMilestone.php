<?php

namespace App\Actions\MealPlans;

use App\Enums\MealPlanMilestoneKind;
use App\Models\MealPlan;
use App\Models\MealPlanMilestone;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RecordMealPlanMilestone
{
    public function handle(MealPlan $mealPlan, User $user, MealPlanMilestoneKind $kind): MealPlanMilestone
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        return DB::transaction(function () use ($mealPlan, $user, $kind): MealPlanMilestone {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $milestone = $mealPlan->milestones()->updateOrCreate(
                ['kind' => $kind],
                ['team_id' => $mealPlan->team_id, 'user_id' => $user->id, 'plan_revision' => $mealPlan->revision, 'achieved_at' => now()],
            );

            if ($kind === MealPlanMilestoneKind::PlanningConfirmed) {
                $mealPlan->update(['planning_confirmed_at' => now(), 'derived_data_stale_at' => null, 'derived_data_stale_reason' => null]);
            }

            if ($kind === MealPlanMilestoneKind::ShoppingListGenerated) {
                $mealPlan->update(['derived_data_stale_at' => null, 'derived_data_stale_reason' => null]);
            }

            return $milestone;
        });
    }
}
