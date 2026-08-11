<?php

namespace Database\Factories;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Models\BasketRunStatusTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasketRunStatusTransition>
 */
class BasketRunStatusTransitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'basket_run_id' => BasketRun::factory(),
            'team_id' => fn (array $attributes) => BasketRun::query()
                ->findOrFail((int) $attributes['basket_run_id'])
                ->team_id,
            'from_status' => BasketRunStatus::WaitingForRecipes,
            'to_status' => BasketRunStatus::BuildingRequirements,
            'duration_ms' => 0,
            'transitioned_at' => now(),
        ];
    }
}
