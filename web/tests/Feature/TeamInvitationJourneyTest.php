<?php

use App\Actions\Teams\BuildTeamInvitationUrl;
use App\Actions\Teams\CreateTeamForUser;
use App\Actions\Teams\InviteUserToTeam;
use App\Models\Person;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('carries minimal invitation context through login and returns to the invitation', function () {
    $owner = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Daniel & Tahlia');
    $invitee = User::factory()->create(['email' => 'tahlia@example.test']);
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, $invitee->email);
    $invitationUrl = app(BuildTeamInvitationUrl::class)->handle($invitation);

    $this->get($invitationUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $invitationUrl);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingInvitation.householdName', 'Daniel & Tahlia')
            ->where('pendingInvitation.inviterName', 'Daniel')
            ->where('pendingInvitation.url', $invitationUrl)
            ->missing('pendingInvitation.email')
            ->missing('pendingInvitation.person'));

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingInvitation.householdName', 'Daniel & Tahlia'));

    $this->post(route('login.store'), [
        'email' => $invitee->email,
        'password' => 'password',
    ])->assertRedirect($invitationUrl);

    $this->get($invitationUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitation.team.name', 'Daniel & Tahlia')
            ->where('invitation.matches_user', true));
});

it('carries invitation context through registration', function () {
    $owner = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Daniel & Tahlia');
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, 'tahlia@example.test');
    $invitationUrl = app(BuildTeamInvitationUrl::class)->handle($invitation);

    $this->get($invitationUrl)->assertRedirect(route('login'));

    $this->post(route('register.store'), [
        'name' => 'Tahlia',
        'email' => 'tahlia@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect($invitationUrl);

    $this->get($invitationUrl)
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitation.matches_user', true));
});

it('rejects tampered expired and mismatched invitation visits without exposing context', function () {
    $owner = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Private household');
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, 'invitee@example.test');
    $invitationUrl = app(BuildTeamInvitationUrl::class)->handle($invitation);
    $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $invitationUrl);

    $this->get($tamperedUrl)->assertForbidden();
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('pendingInvitation', null));

    $outsider = User::factory()->create(['email' => 'outsider@example.test']);
    $this->actingAs($outsider)
        ->get($invitationUrl)
        ->assertForbidden()
        ->assertSessionMissing('pending_team_invitation_id');

    $invitation->update(['expires_at' => now()->subMinute()]);
    $expiredUrl = app(BuildTeamInvitationUrl::class)->handle($invitation->fresh());

    $this->actingAsGuest()->get($expiredUrl)->assertForbidden();
});

it('accepts once links the existing person and clears pending invitation context', function () {
    $owner = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Daniel & Tahlia');
    $person = Person::factory()->for($team)->create(['name' => 'Tahlia']);
    $invitee = User::factory()->create(['email' => 'tahlia@example.test']);
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, $invitee->email, $person);
    $invitationUrl = app(BuildTeamInvitationUrl::class)->handle($invitation);

    $this->get($invitationUrl);
    $this->actingAs($invitee)->get($invitationUrl)->assertOk();
    $this->post(route('team-invitations.accept', $invitation))
        ->assertRedirect()
        ->assertSessionMissing('pending_team_invitation_id');
    $this->post(route('team-invitations.accept', $invitation))
        ->assertSessionHasErrors('invitation');

    expect($invitee->fresh()->current_team_id)->toBe($team->id)
        ->and($person->userLink()->sole()->user_id)->toBe($invitee->id)
        ->and($team->people()->count())->toBe(2)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});
