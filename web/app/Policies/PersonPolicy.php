<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;

class PersonPolicy
{
    public function view(User $user, Person $person): bool
    {
        return $user->memberships()->where('team_id', $person->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->memberships()->whereBelongsTo($team)->exists();
    }

    public function update(User $user, Person $person): bool
    {
        return $this->view($user, $person);
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->memberships()
            ->where('team_id', $person->team_id)
            ->whereIn('role', [TeamRole::Owner, TeamRole::Admin])
            ->exists();
    }
}
