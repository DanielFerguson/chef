<?php

namespace Database\Factories;

use App\Enums\ConstraintKind;
use App\Models\Constraint;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Constraint> */
class ConstraintFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'person_id' => null,
            'created_by_user_id' => null,
            'kind' => ConstraintKind::Other,
            'subject' => fake()->word(),
            'explicitly_confirmed_at' => now(),
        ];
    }
}
