<?php

namespace App\Http\Controllers;

use App\Actions\Planning\SelectPlannedMeal;
use App\Enums\PlannedMealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\RecipeVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealSlotPlannedMealController extends Controller
{
    public function store(Request $request, MealSlot $mealSlot, SelectPlannedMeal $selectMeal): RedirectResponse
    {
        $this->authorize('update', $mealSlot->mealPlan);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(PlannedMealType::class)],
            'recipe_version_id' => ['nullable', 'integer'],
            'source_planned_meal_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'servings' => ['nullable', 'numeric', 'min:0.25', 'max:999'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);
        $recipeVersion = isset($validated['recipe_version_id'])
            ? RecipeVersion::query()->where('team_id', $mealSlot->team_id)->whereKey((int) $validated['recipe_version_id'])->firstOrFail()
            : null;
        $sourceMeal = isset($validated['source_planned_meal_id'])
            ? PlannedMeal::query()->where('team_id', $mealSlot->team_id)->whereKey((int) $validated['source_planned_meal_id'])->firstOrFail()
            : null;

        $selectMeal->handle(
            $mealSlot,
            $request->user(),
            PlannedMealType::from($validated['type']),
            $recipeVersion,
            $validated['title'] ?? null,
            $validated['summary'] ?? null,
            isset($validated['servings']) ? (float) $validated['servings'] : null,
            $validated['estimated_minutes'] ?? null,
            isset($validated['estimated_cost']) ? (float) $validated['estimated_cost'] : null,
            $sourceMeal,
            $validated['expected_revision'] ?? null,
        );

        return back();
    }
}
