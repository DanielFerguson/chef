<?php

namespace App\Http\Controllers;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\BuildTeamInvitationUrl;
use App\Actions\Teams\InviteUserToTeam;
use App\Actions\Teams\PendingTeamInvitation;
use App\Models\Person;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function store(
        Request $request,
        InviteUserToTeam $invite,
        BuildTeamInvitationUrl $buildInvitationUrl,
    ): RedirectResponse {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'person_id' => ['nullable', 'integer'],
        ]);
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $person = isset($validated['person_id'])
            ? Person::query()
                ->where('team_id', $team->id)
                ->whereDoesntHave('userLink')
                ->whereKey($validated['person_id'])
                ->firstOrFail()
            : null;
        $invitation = $invite->handle($team, $request->user(), $validated['email'], $person);

        return back()->with('invitation_url', $buildInvitationUrl->handle($invitation));
    }

    public function show(
        Request $request,
        TeamInvitation $teamInvitation,
        PendingTeamInvitation $pendingInvitation,
    ): Response|RedirectResponse {
        abort_if($teamInvitation->accepted_at !== null || $teamInvitation->expires_at->isPast(), 410);

        if ($request->user() === null) {
            $pendingInvitation->remember($request, $teamInvitation);

            return redirect()->guest(route('login'));
        }

        if (mb_strtolower($request->user()->email) !== mb_strtolower($teamInvitation->email)) {
            $pendingInvitation->forget($request);

            abort(403);
        }

        return Inertia::render('invitations/show', [
            'invitation' => [
                'email' => $teamInvitation->email,
                'team' => $teamInvitation->team->only(['id', 'name']),
                'inviter' => $teamInvitation->inviter?->only(['name']),
                'person' => $teamInvitation->person?->only(['id', 'name']),
                'accept_url' => route('team-invitations.accept', $teamInvitation),
                'matches_user' => mb_strtolower($request->user()->email) === mb_strtolower($teamInvitation->email),
            ],
        ]);
    }

    public function accept(
        Request $request,
        TeamInvitation $teamInvitation,
        AcceptTeamInvitation $accept,
        PendingTeamInvitation $pendingInvitation,
    ): RedirectResponse {
        $accept->handle($teamInvitation, $request->user());
        $pendingInvitation->forget($request);
        $plan = $teamInvitation->team->mealPlans()->latest()->first();

        return $plan === null ? to_route('dashboard') : to_route('meal-plans.show', $plan);
    }
}
