<?php

namespace App\Ai\Tools;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Models\MealPlan;
use App\Models\ShoppingListItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectPlanShoppingList implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly AssessMealPlanReadiness $assessReadiness,
    ) {}

    public function description(): Stringable|string
    {
        return 'Inspect whole-plan recipe-generation progress and the current structured shopping list, including item identifiers, pantry state, revision, and budget.';
    }

    public function handle(Request $request): Stringable|string
    {
        $mealPlan = $this->mealPlan->load([
            'plannedMeals:id,meal_plan_id,title,recipe_version_id',
            'shoppingList.items',
            'budget',
        ]);

        return json_encode([
            'plan_progress' => $this->assessReadiness->handle($mealPlan),
            'recipe_generation' => [
                'status' => $mealPlan->recipe_generation_status instanceof MealPlanRecipeGenerationStatus
                    ? $mealPlan->recipe_generation_status->value
                    : 'not_started',
                'attempts' => $mealPlan->recipe_generation_attempts,
                'failure_message' => $mealPlan->recipe_generation_failure_message,
                'missing_recipe_count' => $mealPlan->plannedMeals->whereNull('recipe_version_id')->count(),
            ],
            'shopping_list' => $mealPlan->shoppingList === null ? null : [
                'id' => $mealPlan->shoppingList->id,
                'revision' => $mealPlan->shoppingList->revision,
                'status' => $mealPlan->shoppingList->status->value,
                'stale_at' => $mealPlan->shoppingList->stale_at,
                'items' => $mealPlan->shoppingList->items->map(fn (ShoppingListItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category->value,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'note' => $item->note,
                    'included' => $item->included,
                    'in_pantry' => $item->in_pantry,
                    'checked' => $item->checked,
                    'source_kind' => $item->source_kind->value,
                ]),
            ],
            'budget' => $mealPlan->budget?->amount,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
