<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DeleteMealPlan
{
    public function handle(MealPlan $mealPlan, User $user): void
    {
        if (! $user->can('delete', $mealPlan)) {
            throw new AuthorizationException('You cannot delete this meal plan.');
        }

        DB::transaction(function () use ($mealPlan): void {
            // Conversations are nullable at the database boundary so they can exist
            // independently. A plan deletion is explicit, so remove its history too.
            $mealPlan->conversations()->delete();
            $mealPlan->delete();
        });
    }
}
