<?php

namespace Database\Seeders;

use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\TeamRole;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $daniel = User::factory()->create([
            'name' => 'Daniel',
            'email' => 'daniel@example.com',
        ]);
        $tahlia = User::factory()->create([
            'name' => 'Tahlia',
            'email' => 'tahlia@example.com',
        ]);

        $family = app(CreateTeamForUser::class)->handle($daniel, 'Daniel & Tahlia');
        app(AddUserToTeam::class)->handle($family, $tahlia, TeamRole::Member);
        Person::query()->create(['team_id' => $family->id, 'name' => 'Dinner guest']);

        $otherUser = User::factory()->create([
            'name' => 'Other Household',
            'email' => 'other@example.com',
        ]);
        app(CreateTeamForUser::class)->handle($otherUser, 'Other household');
    }
}
