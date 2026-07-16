<?php

namespace App\Ai\Tools;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Actions\Shopping\PrepareMealPlanShoppingList;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PreparePlanShoppingList implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly PrepareMealPlanShoppingList $prepare,
        private readonly AssessMealPlanReadiness $assessReadiness,
    ) {}

    public function description(): Stringable|string
    {
        return 'Prepare missing recipes and generate the traceable shopping list for the confirmed plan. Use this when the household asks to begin shopping or build the list.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->prepare->handle($this->mealPlan, $this->actor);
        $plan = $this->mealPlan->refresh()->load('shoppingList.items');

        return json_encode([
            'status' => $plan->shoppingList === null ? 'preparing' : 'ready',
            'plan_progress' => $this->assessReadiness->handle($plan),
            'shopping_list_id' => $plan->shoppingList?->id,
            'shopping_list_revision' => $plan->shoppingList?->revision,
            'item_count' => $plan->shoppingList?->items->count() ?? 0,
            'next_action' => $plan->shoppingList === null ? 'wait_for_preparation' : 'review_pantry_and_extras',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
