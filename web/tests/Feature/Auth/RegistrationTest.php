<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\UserPersonLink;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->assertSame(1, Team::query()->count());
    $this->assertSame($user->current_team_id, $user->teams()->sole()->id);
    $this->assertSame(
        TeamRole::Owner,
        TeamMembership::query()->whereBelongsTo($user)->sole()->role,
    );
    $this->assertSame(
        $user->id,
        UserPersonLink::query()->where('team_id', $user->current_team_id)->sole()->user_id,
    );
});
