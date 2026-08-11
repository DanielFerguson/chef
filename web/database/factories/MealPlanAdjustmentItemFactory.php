<?php

namespace Database\Factories;

use App\Models\MealPlanAdjustmentDraft;
use App\Models\MealPlanAdjustmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanAdjustmentItem>
 */
class MealPlanAdjustmentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meal_plan_adjustment_draft_id' => MealPlanAdjustmentDraft::factory(),
            'team_id' => fn (array $attributes) => MealPlanAdjustmentDraft::query()
                ->findOrFail((int) $attributes['meal_plan_adjustment_draft_id'])
                ->team_id,
            'replacement_title' => fake()->words(3, true),
            'replacement_summary' => fake()->sentence(),
            'estimated_minutes' => 30,
            'estimated_cost_cents' => 1_000,
            'covered_requirement_ids' => [],
        ];
    }
}
