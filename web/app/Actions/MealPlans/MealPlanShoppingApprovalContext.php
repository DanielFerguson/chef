<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;

class MealPlanShoppingApprovalContext
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    public function fingerprint(MealPlan $mealPlan): string
    {
        return hash('sha256', json_encode([
            'meal_plan_id' => $mealPlan->id,
            'revision' => $mealPlan->revision,
            'safety_context' => $this->safetyContext->fingerprint($mealPlan),
            'retailer' => 'woolworths',
        ], JSON_THROW_ON_ERROR));
    }
}
