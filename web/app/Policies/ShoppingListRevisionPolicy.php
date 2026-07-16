<?php

namespace App\Policies;

use App\Models\ShoppingListRevision;
use App\Models\User;

class ShoppingListRevisionPolicy
{
    public function view(User $user, ShoppingListRevision $revision): bool
    {
        return $user->memberships()->where('team_id', $revision->team_id)->exists();
    }
}
