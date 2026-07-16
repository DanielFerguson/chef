<?php

namespace Database\Factories;

use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\MealSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealSlot> */
class MealSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => MealPlan::query()->findOrFail((int) $attributes['meal_plan_id'])->team_id,
            'meal_plan_id' => MealPlan::factory(),
            'date' => now()->startOfDay(),
            'kind' => MealSlotKind::Dinner,
            'position' => 0,
        ];
    }
}
