<?php

namespace App\Policies;

use App\Models\RetailerProductPreference;
use App\Models\Team;
use App\Models\User;

class RetailerProductPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->memberships()->where('team_id', $user->current_team_id)->exists();
    }

    public function view(User $user, RetailerProductPreference $retailerProductPreference): bool
    {
        return $user->memberships()->where('team_id', $retailerProductPreference->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->memberships()->whereBelongsTo($team)->exists();
    }

    public function update(User $user, RetailerProductPreference $retailerProductPreference): bool
    {
        return $this->view($user, $retailerProductPreference);
    }
}
