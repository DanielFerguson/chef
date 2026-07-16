<?php

namespace App\Policies;

use App\Models\MealPlanMilestone;
use App\Models\User;

class MealPlanMilestonePolicy
{
    public function view(User $user, MealPlanMilestone $milestone): bool
    {
        return $user->memberships()->where('team_id', $milestone->team_id)->exists();
    }
}
