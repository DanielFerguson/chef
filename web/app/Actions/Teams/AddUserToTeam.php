<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Models\UserPersonLink;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddUserToTeam
{
    public function handle(
        Team $team,
        User $user,
        TeamRole $role = TeamRole::Member,
        ?Person $person = null,
    ): Person {
        return DB::transaction(function () use ($team, $user, $role, $person): Person {
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

            if ($person !== null && $person->team_id !== $team->id) {
                throw new AuthorizationException('That person does not belong to this family.');
            }

            if ($person !== null && $person->userLink()->exists()) {
                throw ValidationException::withMessages([
                    'person_id' => 'That person is already linked to an account.',
                ]);
            }

            $person ??= Person::query()->create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
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
