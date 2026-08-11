<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\MealPlanPurchasePreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanPurchasePreference>
 */
class MealPlanPurchasePreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => MealPlan::query()->findOrFail((int) $attributes['meal_plan_id'])->team_id,
            'meal_plan_id' => MealPlan::factory(),
            'provider' => 'coles',
            'basket_target_cents' => fake()->numberBetween(5_000, 25_000),
        ];
    }
}
