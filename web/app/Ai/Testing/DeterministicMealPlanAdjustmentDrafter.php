<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\MealPlanAdjustmentDrafter;
use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Ai\Data\MealPlanAdjustmentDraftResult;

class DeterministicMealPlanAdjustmentDrafter implements MealPlanAdjustmentDrafter
{
    public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult
    {
        $meal = $request->meals[0] ?? null;

        if (! is_array($meal)) {
            return new MealPlanAdjustmentDraftResult([]);
        }

        return new MealPlanAdjustmentDraftResult([[
            'planned_meal_id' => $meal['planned_meal_id'],
            'meal_slot_id' => $meal['meal_slot_id'],
            'title' => 'Alternative '.$meal['title'],
            'summary' => 'A deterministic test-only adjustment proposal.',
            'estimated_minutes' => $meal['estimated_minutes'],
            'estimated_cost_cents' => $request->budgetTargetCents,
            'covered_requirement_ids' => array_column($request->blockedRequirements, 'requirement_id'),
        ]]);
    }
}
