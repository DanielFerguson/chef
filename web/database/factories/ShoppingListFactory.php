<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\ShoppingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShoppingList> */
class ShoppingListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meal_plan_id' => MealPlan::factory(),
            'team_id' => fn (array $attributes) => MealPlan::query()->findOrFail((int) $attributes['meal_plan_id'])->team_id,
            'created_by_user_id' => null,
            'revision' => 1,
            'source_plan_revision' => 1,
            'status' => 'draft',
        ];
    }
}
