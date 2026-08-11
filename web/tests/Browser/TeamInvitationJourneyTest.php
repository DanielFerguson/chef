<?php

use App\Actions\Teams\BuildTeamInvitationUrl;
use App\Actions\Teams\CreateTeamForUser;
use App\Actions\Teams\InviteUserToTeam;
use App\Models\User;

it('shows the pending household invitation clearly on desktop and narrow screens', function () {
    $owner = User::factory()->create(['name' => 'Daniel']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'Daniel & Tahlia');
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, 'tahlia@example.test');
    $invitationUrl = app(BuildTeamInvitationUrl::class)->handle($invitation);

    visit($invitationUrl)->on()->desktop()
        ->assertPathIs('/login')
        ->assertSee('You’ve been invited to join Daniel & Tahlia')
        ->assertSee('Daniel invited you to plan meals together.')
        ->assertPresent('[data-test="pending-invitation-banner"]')
        ->assertNoJavaScriptErrors()
        ->resize(390, 844)
        ->assertSee('You’ve been invited to join Daniel & Tahlia')
        ->assertPresent('[data-test="pending-invitation-banner"]')
        ->assertNoJavaScriptErrors();
});
