<?php

namespace App\Http\Controllers;

use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Models\Budget;
use App\Models\MealPlan;
use App\Models\Retailer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShoppingListController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);

        $plans = $team->mealPlans()
            ->whereNotNull('planning_confirmed_at')
            ->with('shoppingList:id,meal_plan_id,status,revision,stale_at,updated_at')
            ->latest('starts_on')
            ->get(['id', 'team_id', 'title', 'starts_on', 'ends_on', 'planning_confirmed_at']);

        return Inertia::render('shopping/index', ['plans' => $plans]);
    }

    public function show(Request $request, MealPlan $mealPlan): Response
    {
        $this->authorize('view', $mealPlan);
        $mealPlan->load([
            'shoppingList.items.sources.plannedMeal.mealSlot',
            'shoppingList.items.productMatch.retailProduct.retailer',
            'shoppingList.revisions' => fn ($query) => $query->limit(10),
            'shoppingList.mealResolutions',
            'shoppingList.orders.retailer',
            'plannedMeals.mealSlot',
            'plannedMeals.recipeVersion:id,team_id,recipe_id,title,servings',
        ]);
        $shoppingList = $mealPlan->shoppingList;
        $resolvedMealIds = $shoppingList?->mealResolutions->pluck('planned_meal_id') ?? collect();
        $missingMeals = $mealPlan->plannedMeals
            ->filter(fn ($meal) => $meal->getRawOriginal('status') === PlannedMealStatus::Planned->value
                && $meal->getRawOriginal('type') === PlannedMealType::Custom->value
                && $meal->recipe_version_id === null
                && ! $resolvedMealIds->contains($meal->id))
            ->values()
            ->map(fn ($meal) => [
                'id' => $meal->id,
                'title' => $meal->title,
                'date' => $meal->mealSlot->date->toDateString(),
                'kind' => $meal->mealSlot->kind->value,
            ]);
        $householdBudget = $mealPlan->team->budgets()->whereNull('meal_plan_id')->latest()->first();
        $planBudget = Budget::query()->where('team_id', $mealPlan->team_id)->where('meal_plan_id', $mealPlan->id)->first();
        $projectedTotal = $shoppingList?->items
            ->filter(fn ($item) => $item->included && ! $item->in_pantry)
            ->sum(fn ($item): float => (float) ($item->estimated_price ?? 0)) ?? 0;
        $unmatchedItems = $shoppingList?->items
            ->filter(fn ($item) => $item->included && ! $item->in_pantry && $item->productMatch === null && $item->estimated_price === null)
            ->count() ?? 0;

        $effectiveBudget = $planBudget !== null ? $planBudget->amount : $householdBudget?->amount;

        return Inertia::render('shopping/show', [
            'workspace' => [
                'plan' => [
                    'id' => $mealPlan->id,
                    'title' => $mealPlan->title,
                    'starts_on' => $mealPlan->starts_on->toDateString(),
                    'ends_on' => $mealPlan->ends_on->toDateString(),
                    'revision' => $mealPlan->revision,
                    'planning_confirmed_at' => $mealPlan->planning_confirmed_at?->toIso8601String(),
                ],
                'shopping_list' => $shoppingList,
                'missing_meals' => $missingMeals,
                'retailers' => Retailer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'slug']),
                'product_preferences' => $mealPlan->team->productPreferences()
                    ->with('retailer:id,name,slug')
                    ->orderBy('normalized_item_name')
                    ->get(),
                'budget' => [
                    'household_default' => $householdBudget?->amount,
                    'plan_override' => $planBudget?->amount,
                    'effective' => $effectiveBudget,
                    'projected_total' => round($projectedTotal, 2),
                    'unmatched_items' => $unmatchedItems,
                    'currency' => 'AUD',
                ],
            ],
        ]);
    }
}
