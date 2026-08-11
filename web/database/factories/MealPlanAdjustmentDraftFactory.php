<?php

namespace Database\Factories;

use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Models\BasketRun;
use App\Models\MealPlanAdjustmentDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlanAdjustmentDraft>
 */
class MealPlanAdjustmentDraftFactory extends Factory
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
            'meal_plan_id' => fn (array $attributes) => BasketRun::query()->findOrFail((int) $attributes['basket_run_id'])->meal_plan_id,
            'team_id' => fn (array $attributes) => BasketRun::query()->findOrFail((int) $attributes['basket_run_id'])->team_id,
            'originating_plan_revision' => 1,
            'kind' => MealPlanAdjustmentKind::ProductUnavailable,
            'status' => MealPlanAdjustmentDraftStatus::Pending,
            'input_fingerprint' => hash('sha256', fake()->uuid()),
            'generated_at' => now(),
        ];
    }
}
