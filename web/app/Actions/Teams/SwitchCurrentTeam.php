<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class SwitchCurrentTeam
{
    public function handle(User $user, Team $team): void
    {
        if (! $user->teams()->whereKey($team->getKey())->exists()) {
            throw new AuthorizationException('You do not belong to this family.');
        }

        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
