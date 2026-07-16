<?php

namespace App\Policies;

use App\Models\PlannedMeal;
use App\Models\User;

class PlannedMealPolicy
{
    public function update(User $user, PlannedMeal $plannedMeal): bool
    {
        return $user->memberships()->where('team_id', $plannedMeal->team_id)->exists();
    }
}
