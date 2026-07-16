<?php

namespace Database\Factories;

use App\Models\MealPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlan> */
class MealPlanFactory extends Factory
{
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('now', '+1 month');

        return [
            'team_id' => Team::factory(),
            'created_by_user_id' => User::factory(),
            'title' => 'Meal plan',
            'starts_on' => $startsOn,
            'ends_on' => (clone $startsOn)->modify('+6 days'),
        ];
    }
}
