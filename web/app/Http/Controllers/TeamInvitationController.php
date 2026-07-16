<?php

namespace App\Http\Controllers;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\InviteUserToTeam;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function store(Request $request, InviteUserToTeam $invite): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $invitation = $invite->handle($team, $request->user(), $validated['email']);

        return back()->with('invitation_url', route('team-invitations.show', $invitation));
    }

    public function show(Request $request, TeamInvitation $teamInvitation): Response
    {
        abort_if($teamInvitation->accepted_at !== null || $teamInvitation->expires_at->isPast(), 410);

        return Inertia::render('invitations/show', [
            'invitation' => [
                'email' => $teamInvitation->email,
                'team' => $teamInvitation->team->only(['id', 'name']),
                'inviter' => $teamInvitation->inviter?->only(['name']),
                'accept_url' => route('team-invitations.accept', $teamInvitation),
                'matches_user' => mb_strtolower($request->user()->email) === mb_strtolower($teamInvitation->email),
            ],
        ]);
    }

    public function accept(Request $request, TeamInvitation $teamInvitation, AcceptTeamInvitation $accept): RedirectResponse
    {
        $accept->handle($teamInvitation, $request->user());
        $plan = $teamInvitation->team->mealPlans()->latest()->first();

        return $plan === null ? to_route('dashboard') : to_route('meal-plans.show', $plan);
    }
}
