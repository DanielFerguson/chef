<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;

class DeterministicMealPlanRecipeDrafter implements MealPlanRecipeDrafter
{
    public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
    {
        return new MealPlanRecipeDraft(array_map(fn (array $meal): array => [
            'planned_meal_id' => $meal['planned_meal_id'],
            'title' => $meal['title'],
            'summary' => $meal['summary'] ?? 'A practical Chef-prepared recipe.',
            'servings' => $meal['servings'],
            'prep_minutes' => 10,
            'cook_minutes' => max(10, ($meal['estimated_minutes'] ?? 30) - 10),
            'ingredients' => [[
                'name' => $meal['title'].' ingredients',
                'quantity' => 1.0,
                'unit' => 'batch',
                'preparation' => null,
                'optional' => false,
            ]],
            'steps' => [[
                'instruction' => 'Prepare and cook '.$meal['title'].'.',
                'timer_minutes' => null,
            ]],
            'equipment' => ['Pan'],
            'notices' => [],
            'storage_guidance' => null,
        ], $request->meals));
    }
}
