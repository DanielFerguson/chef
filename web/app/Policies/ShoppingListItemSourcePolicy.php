<?php

namespace App\Policies;

use App\Models\ShoppingListItemSource;
use App\Models\User;

class ShoppingListItemSourcePolicy
{
    public function view(User $user, ShoppingListItemSource $source): bool
    {
        return $user->memberships()->where('team_id', $source->team_id)->exists();
    }
}
