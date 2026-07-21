<?php

namespace App\Policies;

use App\Models\RetailerConnection;
use App\Models\User;

class RetailerConnectionPolicy
{
    public function view(User $user, RetailerConnection $connection): bool
    {
        return $user->memberships()->where('team_id', $connection->team_id)->exists();
    }

    public function authenticate(User $user, RetailerConnection $connection): bool
    {
        return $this->view($user, $connection) && $connection->owner_user_id === $user->id;
    }

    public function disconnect(User $user, RetailerConnection $connection): bool
    {
        return $this->authenticate($user, $connection);
    }

    public function useForAutomation(User $user, RetailerConnection $connection): bool
    {
        return $this->authenticate($user, $connection);
    }
}
