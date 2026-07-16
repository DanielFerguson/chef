<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\UserPersonLink;
use Illuminate\Support\Facades\DB;

class AddUserToTeam
{
    public function handle(Team $team, User $user, TeamRole $role = TeamRole::Member): Person
    {
        return DB::transaction(function () use ($team, $user, $role): Person {
            TeamMembership::query()->firstOrCreate(
                ['team_id' => $team->id, 'user_id' => $user->id],
                ['role' => $role],
            );

            $link = UserPersonLink::query()
                ->whereBelongsTo($team)
                ->whereBelongsTo($user)
                ->first();

            if ($link) {
                return $link->person;
            }

            $person = Person::query()->create([
                'team_id' => $team->id,
                'name' => $user->name,
            ]);

            UserPersonLink::query()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'person_id' => $person->id,
            ]);

            if ($user->current_team_id === null) {
                $user->forceFill(['current_team_id' => $team->id])->save();
            }

            return $person;
        });
    }
}
