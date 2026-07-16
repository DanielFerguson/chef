<?php

namespace App\Policies;

use App\Models\OrderLine;
use App\Models\User;

class OrderLinePolicy
{
    public function view(User $user, OrderLine $line): bool
    {
        return $user->memberships()->where('team_id', $line->team_id)->exists();
    }
}
