<?php

namespace App\Policies;

use App\Models\MealOutcome;
use App\Models\User;

class MealOutcomePolicy
{
    public function view(User $user, MealOutcome $mealOutcome): bool
    {
        return $user->memberships()->where('team_id', $mealOutcome->team_id)->exists();
    }

    public function update(User $user, MealOutcome $mealOutcome): bool
    {
        return $this->view($user, $mealOutcome);
    }
}
