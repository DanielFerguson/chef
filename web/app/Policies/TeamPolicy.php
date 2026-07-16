<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $this->role($user, $team) !== null;
    }

    public function update(User $user, Team $team): bool
    {
        return in_array($this->role($user, $team), [TeamRole::Owner, TeamRole::Admin], true);
    }

    public function invite(User $user, Team $team): bool
    {
        return $this->update($user, $team);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->role($user, $team) === TeamRole::Owner;
    }

    private function role(User $user, Team $team): ?TeamRole
    {
        $membership = $user->memberships()->whereBelongsTo($team)->first();

        return $membership?->role;
    }
}
