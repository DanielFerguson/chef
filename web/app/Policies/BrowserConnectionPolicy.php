<?php

namespace App\Policies;

use App\Models\BrowserConnection;
use App\Models\User;

class BrowserConnectionPolicy
{
    public function view(User $user, BrowserConnection $connection): bool
    {
        return $user->memberships()->where('team_id', $connection->team_id)->exists();
    }

    public function update(User $user, BrowserConnection $connection): bool
    {
        return $user->can('manageIntegrations', $connection->team);
    }
}
