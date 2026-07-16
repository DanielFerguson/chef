<?php

namespace App\Policies;

use App\Models\MealSlot;
use App\Models\User;

class MealSlotPolicy
{
    public function view(User $user, MealSlot $mealSlot): bool
    {
        return $user->memberships()->where('team_id', $mealSlot->team_id)->exists();
    }

    public function update(User $user, MealSlot $mealSlot): bool
    {
        return $this->view($user, $mealSlot);
    }
}
