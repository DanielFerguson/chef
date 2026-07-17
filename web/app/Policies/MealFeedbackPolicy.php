<?php

namespace App\Policies;

use App\Models\MealFeedback;
use App\Models\User;

class MealFeedbackPolicy
{
    public function view(User $user, MealFeedback $mealFeedback): bool
    {
        return $user->memberships()->where('team_id', $mealFeedback->team_id)->exists();
    }

    public function update(User $user, MealFeedback $mealFeedback): bool
    {
        return $this->view($user, $mealFeedback);
    }
}
