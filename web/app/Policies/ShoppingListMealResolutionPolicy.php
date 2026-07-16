<?php

namespace App\Policies;

use App\Models\ShoppingListMealResolution;
use App\Models\User;

class ShoppingListMealResolutionPolicy
{
    public function view(User $user, ShoppingListMealResolution $resolution): bool
    {
        return $user->memberships()->where('team_id', $resolution->team_id)->exists();
    }

    public function update(User $user, ShoppingListMealResolution $resolution): bool
    {
        return $this->view($user, $resolution);
    }
}
