<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class InviteUserToTeam
{
    public function handle(Team $team, User $inviter, string $email): TeamInvitation
    {
        if (! $inviter->can('invite', $team)) {
            throw new AuthorizationException('You cannot invite people to this family.');
        }

        return TeamInvitation::query()->updateOrCreate(
            ['team_id' => $team->id, 'email' => Str::lower(trim($email)), 'accepted_at' => null],
            [
                'role' => TeamRole::Member,
                'token' => hash('sha256', Str::random(64)),
                'invited_by_user_id' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ],
        );
    }
}
