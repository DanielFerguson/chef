<?php

namespace App\Policies;

use App\Models\RetailerPurchasePolicy;
use App\Models\Team;
use App\Models\User;

class RetailerPurchasePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->memberships()->where('team_id', $user->current_team_id)->exists();
    }

    public function view(User $user, RetailerPurchasePolicy $policy): bool
    {
        return $user->memberships()->where('team_id', $policy->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->can('update', $team);
    }

    public function update(User $user, RetailerPurchasePolicy $policy): bool
    {
        return $user->can('update', $policy->team);
    }
}
