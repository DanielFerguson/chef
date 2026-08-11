<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\MealPlanPurchasePreference;
use App\Models\User;

class MealPlanPurchasePreferencePolicy
{
    public function view(User $user, MealPlanPurchasePreference $preference): bool
    {
        return $user->can('view', $preference->mealPlan);
    }

    public function create(User $user, MealPlan $mealPlan): bool
    {
        return $user->can('update', $mealPlan);
    }

    public function update(User $user, MealPlanPurchasePreference $preference): bool
    {
        return $user->can('update', $preference->mealPlan);
    }
}
