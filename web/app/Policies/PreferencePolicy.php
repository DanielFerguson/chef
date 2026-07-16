<?php

namespace App\Policies;

use App\Models\Preference;
use App\Models\User;

class PreferencePolicy
{
    public function update(User $user, Preference $preference): bool
    {
        return $user->memberships()->where('team_id', $preference->team_id)->exists();
    }

    public function delete(User $user, Preference $preference): bool
    {
        return $this->update($user, $preference);
    }
}
