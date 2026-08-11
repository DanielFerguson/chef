<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use Illuminate\Http\Request;

class PendingTeamInvitation
{
    private const SESSION_KEY = 'pending_team_invitation_id';

    public function __construct(private readonly BuildTeamInvitationUrl $buildUrl) {}

    public function remember(Request $request, TeamInvitation $invitation): void
    {
        $request->session()->put(self::SESSION_KEY, $invitation->id);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{householdName: string, inviterName: string|null, url: string}|null
     */
    public function shared(Request $request): ?array
    {
        $invitationId = $request->session()->get(self::SESSION_KEY);

        if (! is_int($invitationId)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with([
                'team:id,name',
                'inviter:id,name',
            ])
            ->find($invitationId);

        if ($invitation === null
            || $invitation->accepted_at !== null
            || $invitation->expires_at->isPast()
            || ($request->user() !== null
                && mb_strtolower($request->user()->email) !== mb_strtolower($invitation->email))) {
            $this->forget($request);

            return null;
        }

        return [
            'householdName' => $invitation->team->name,
            'inviterName' => $invitation->inviter?->name,
            'url' => $this->buildUrl->handle($invitation),
        ];
    }
}
