<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

class TeamInvitationPolicy
{
    public function view(User $user, TeamInvitation $invitation): bool
    {
        return $user->can('view', $invitation->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->can('invite', $team);
    }

    public function delete(User $user, TeamInvitation $invitation): bool
    {
        return $user->can('invite', $invitation->team);
    }
}
