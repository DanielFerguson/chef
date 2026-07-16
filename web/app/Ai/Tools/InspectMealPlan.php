<?php

namespace App\Ai\Tools;

use App\Models\MealPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectMealPlan implements Tool
{
    public function __construct(private readonly MealPlan $mealPlan) {}

    public function description(): Stringable|string
    {
        return 'Inspect the current meal plan date span, slots, participants, selected meals, and pending proposals.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->mealPlan->load([
            'slots.participants',
            'slots.plannedMeal.recipeVersion',
            'slots.plannedMeal.sourcePlannedMeal',
            'proposals' => fn ($query) => $query->latest(),
        ])->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
