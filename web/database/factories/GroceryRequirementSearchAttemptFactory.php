<?php

namespace Database\Factories;

use App\Models\GroceryRequirement;
use App\Models\GroceryRequirementSearchAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroceryRequirementSearchAttempt>
 */
class GroceryRequirementSearchAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => GroceryRequirement::query()->findOrFail((int) $attributes['grocery_requirement_id'])->team_id,
            'grocery_requirement_id' => GroceryRequirement::factory(),
            'sequence' => 1,
            'method' => 'deterministic',
            'query' => fake()->words(2, true),
            'result_count' => 0,
            'eligible_result_count' => 0,
            'captured_at' => now(),
        ];
    }
}
