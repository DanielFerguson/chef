<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\Team;
use App\Models\User;

class MealPlanPolicy
{
    public function view(User $user, MealPlan $mealPlan): bool
    {
        return $user->memberships()->where('team_id', $mealPlan->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->memberships()->whereBelongsTo($team)->exists();
    }

    public function update(User $user, MealPlan $mealPlan): bool
    {
        return $this->view($user, $mealPlan);
    }

    public function delete(User $user, MealPlan $mealPlan): bool
    {
        return $user->can('update', $mealPlan->team);
    }
}
