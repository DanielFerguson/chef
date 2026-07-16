<?php

namespace App\Policies;

use App\Models\PlannedMealRecipePreparation;
use App\Models\User;

class PlannedMealRecipePreparationPolicy
{
    public function view(User $user, PlannedMealRecipePreparation $preparation): bool
    {
        return $user->memberships()->where('team_id', $preparation->team_id)->exists();
    }

    public function update(User $user, PlannedMealRecipePreparation $preparation): bool
    {
        return $this->view($user, $preparation);
    }
}
