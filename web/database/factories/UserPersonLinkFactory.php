<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPersonLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserPersonLink> */
class UserPersonLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'person_id' => Person::factory(),
        ];
    }
}
