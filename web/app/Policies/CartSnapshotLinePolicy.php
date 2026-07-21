<?php

namespace App\Policies;

use App\Models\CartSnapshotLine;
use App\Models\User;

class CartSnapshotLinePolicy
{
    public function view(User $user, CartSnapshotLine $line): bool
    {
        return $user->memberships()->where('team_id', $line->team_id)->exists();
    }
}
