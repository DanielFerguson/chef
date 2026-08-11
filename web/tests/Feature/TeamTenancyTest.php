<?php

use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Actions\Teams\InviteUserToTeam;
use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lets two accounts see the same family workspace', function () {
    $owner = User::factory()->create(['name' => 'Alex Owner']);
    $member = User::factory()->create(['name' => 'Sam Member']);
    $team = app(CreateTeamForUser::class)->handle($owner, 'The Test Kitchen');
    app(AddUserToTeam::class)->handle($team, $member);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('household.id', $team->id)
            ->where('household.name', 'The Test Kitchen')
            ->has('household.people', 2));
});

it('forbids switching to another familys workspace by changing the identifier', function () {
    $user = User::factory()->create();
    $ownTeam = app(CreateTeamForUser::class)->handle($user, 'Own family');

    $outsider = User::factory()->create();
    $otherTeam = app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $this->actingAs($user)
        ->put(route('teams.current.update', $otherTeam))
        ->assertForbidden();

    expect($user->refresh()->current_team_id)->toBe($ownTeam->id);
});

it('allows a member to switch between their own families', function () {
    $user = User::factory()->create();
    app(CreateTeamForUser::class)->handle($user, 'First family');

    $secondOwner = User::factory()->create();
    $secondTeam = app(CreateTeamForUser::class)->handle($secondOwner, 'Second family');
    app(AddUserToTeam::class)->handle($secondTeam, $user, TeamRole::Admin);

    $this->actingAs($user)
        ->put(route('teams.current.update', $secondTeam))
        ->assertRedirect();

    expect($user->refresh()->current_team_id)->toBe($secondTeam->id);
});

it('scopes implicit person bindings to the active family', function () {
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Own family');
    $ownPerson = $team->people()->sole();

    $outsider = User::factory()->create();
    $otherTeam = app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    $otherPerson = $otherTeam->people()->sole();

    $this->actingAs($user);

    expect((new Person)->resolveRouteBinding($ownPerson->id)?->is($ownPerson))->toBeTrue()
        ->and((new Person)->resolveRouteBinding($otherPerson->id))->toBeNull();
});

it('applies team policies across the tenancy boundary', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Policy family');
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($team, $member);
    $outsider = User::factory()->create();

    expect($owner->can('delete', $team))->toBeTrue()
        ->and($member->can('view', $team))->toBeTrue()
        ->and($member->can('delete', $team))->toBeFalse()
        ->and($outsider->can('view', $team))->toBeFalse();
});

it('applies person and invitation policies across roles and families', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Policy family');
    $person = $team->people()->sole();
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($team, $member);
    $outsider = User::factory()->create();
    $invitation = app(InviteUserToTeam::class)->handle($team, $owner, 'future@example.test');

    expect($owner->can('create', [Person::class, $team]))->toBeTrue()
        ->and($member->can('update', $person))->toBeTrue()
        ->and($member->can('delete', $person))->toBeFalse()
        ->and($owner->can('delete', $person))->toBeTrue()
        ->and($outsider->can('update', $person))->toBeFalse()
        ->and($owner->can('view', $invitation))->toBeTrue()
        ->and($owner->can('create', [$invitation::class, $team]))->toBeTrue()
        ->and($owner->can('delete', $invitation))->toBeTrue()
        ->and($outsider->can('view', $invitation))->toBeFalse();
});
