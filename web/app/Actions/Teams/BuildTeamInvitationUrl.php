<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\URL;

class BuildTeamInvitationUrl
{
    public function handle(TeamInvitation $invitation): string
    {
        return URL::temporarySignedRoute(
            'team-invitations.show',
            $invitation->expires_at,
            ['teamInvitation' => $invitation],
        );
    }
}
