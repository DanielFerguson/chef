<?php

namespace App\Policies;

use App\Models\CartSnapshot;
use App\Models\User;

class CartSnapshotPolicy
{
    public function view(User $user, CartSnapshot $snapshot): bool
    {
        return $user->memberships()->where('team_id', $snapshot->team_id)->exists();
    }
}
