<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeamForUser
{
    public function __construct(private AddUserToTeam $addUserToTeam) {}

    public function handle(User $user, ?string $name = null): Team
    {
        return DB::transaction(function () use ($user, $name): Team {
            $team = Team::query()->create([
                'name' => $name ?? $user->name.' family',
                'timezone' => config('app.timezone'),
            ]);

            $this->addUserToTeam->handle($team, $user, TeamRole::Owner);
            $user->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });
    }
}
