<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\MealPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => fn (array $attributes) => MealPlan::query()->findOrFail((int) $attributes['meal_plan_id'])->team_id,
            'meal_plan_id' => MealPlan::factory(),
            'created_by_user_id' => null,
            'title' => 'Planning conversation',
        ];
    }
}
