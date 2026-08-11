<?php

namespace App\Policies;

use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;

class RetailerConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->memberships()->where('team_id', $user->current_team_id)->exists();
    }

    public function view(User $user, RetailerConnection $retailerConnection): bool
    {
        return $user->memberships()->where('team_id', $retailerConnection->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->memberships()->whereBelongsTo($team)->exists();
    }

    public function update(User $user, RetailerConnection $retailerConnection): bool
    {
        return $this->view($user, $retailerConnection)
            && $retailerConnection->owner_user_id === $user->id;
    }

    public function delete(User $user, RetailerConnection $retailerConnection): bool
    {
        return $this->update($user, $retailerConnection);
    }

    public function useLiveView(User $user, RetailerConnection $retailerConnection): bool
    {
        return $this->update($user, $retailerConnection);
    }
}
