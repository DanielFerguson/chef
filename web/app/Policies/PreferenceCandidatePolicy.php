<?php

namespace App\Policies;

use App\Models\PreferenceCandidate;
use App\Models\User;

class PreferenceCandidatePolicy
{
    public function view(User $user, PreferenceCandidate $preferenceCandidate): bool
    {
        return $user->memberships()->where('team_id', $preferenceCandidate->team_id)->exists();
    }

    public function update(User $user, PreferenceCandidate $preferenceCandidate): bool
    {
        return $this->view($user, $preferenceCandidate);
    }
}
