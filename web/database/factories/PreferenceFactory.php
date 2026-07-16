<?php

namespace Database\Factories;

use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Preference;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Preference> */
class PreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'person_id' => null,
            'subject' => fake()->word(),
            'sentiment' => PreferenceSentiment::Like,
            'strength' => 3,
            'provenance' => PreferenceProvenance::Stated,
            'confidence' => null,
        ];
    }
}
