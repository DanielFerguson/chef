<?php

namespace Database\Factories;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasketRun>
 */
class BasketRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meal_plan_id' => MealPlan::factory(),
            'team_id' => fn (array $attributes) => MealPlan::query()->findOrFail((int) $attributes['meal_plan_id'])->team_id,
            'requested_by_user_id' => User::factory(),
            'status' => BasketRunStatus::WaitingForRecipes,
            'idempotency_key' => fake()->uuid(),
            'input_fingerprint' => hash('sha256', fake()->uuid()),
        ];
    }
}
