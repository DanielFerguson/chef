<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptTeamInvitation
{
    public function __construct(private readonly AddUserToTeam $addUserToTeam) {}

    public function handle(TeamInvitation $invitation, User $user): void
    {
        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            throw new AuthorizationException('This invitation was sent to another email address.');
        }

        if ($invitation->accepted_at !== null || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer available.']);
        }

        DB::transaction(function () use ($invitation, $user): void {
            $this->addUserToTeam->handle($invitation->team, $user, $invitation->role);
            $invitation->update(['accepted_at' => now()]);
            $user->update(['current_team_id' => $invitation->team_id]);
        });
    }
}
