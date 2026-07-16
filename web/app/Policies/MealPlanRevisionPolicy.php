<?php

namespace App\Policies;

use App\Models\MealPlanRevision;
use App\Models\User;

class MealPlanRevisionPolicy
{
    public function view(User $user, MealPlanRevision $revision): bool
    {
        return $user->memberships()->where('team_id', $revision->team_id)->exists();
    }
}
