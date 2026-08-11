<?php

namespace App\Policies;

use App\Models\GroceryPlan;
use App\Models\User;

class GroceryPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->memberships()->where('team_id', $user->current_team_id)->exists();
    }

    public function view(User $user, GroceryPlan $groceryPlan): bool
    {
        return $user->memberships()->where('team_id', $groceryPlan->team_id)->exists();
    }
}
