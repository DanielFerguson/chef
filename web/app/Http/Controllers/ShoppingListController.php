<?php

namespace App\Http\Controllers;

use App\Enums\BrowserConnectionStatus;
use App\Enums\PlannedMealRecipePreparationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\ShoppingListItemCategory;
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
            'conversations.messages.author:id,name',
            'conversations.messages.feedback' => fn ($query) => $query->whereBelongsTo($request->user()),
            'conversations.feedback' => fn ($query) => $query->whereBelongsTo($request->user())->whereNull('message_id'),
            'shoppingList.items.sources.plannedMeal.mealSlot',
            'shoppingList.items.productMatch.retailProduct.retailer',
            'shoppingList.revisions' => fn ($query) => $query->limit(10),
            'shoppingList.mealResolutions',
            'shoppingList.orders.retailer',
            'shoppingList.automationRuns' => fn ($query) => $query->latest()->limit(5),
            'shoppingList.automationRuns.retailer:id,name,slug',
            'shoppingList.automationRuns.browserConnection:id,uuid,name,status,last_seen_at',
            'shoppingList.automationRuns.approvals',
            'shoppingList.automationRuns.reconciliations',
            'shoppingList.automationRuns.steps',
            'plannedMeals.mealSlot',
            'plannedMeals.recipePreparation',
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
                'preparation_id' => $meal->recipePreparation?->id,
                'preparation_status' => $meal->recipePreparation?->status->value ?? 'not_started',
                'failure_message' => $meal->recipePreparation?->failure_message,
            ]);
        $cookableMeals = $mealPlan->plannedMeals
            ->filter(fn ($meal) => $meal->getRawOriginal('status') === PlannedMealStatus::Planned->value
                && in_array($meal->getRawOriginal('type'), [PlannedMealType::Recipe->value, PlannedMealType::Custom->value], true));
        $recipesReady = $cookableMeals->whereNotNull('recipe_version_id')->count();
        $recipesPreparing = $missingMeals->whereIn('preparation_status', [
            PlannedMealRecipePreparationStatus::Pending->value,
            PlannedMealRecipePreparationStatus::Processing->value,
        ])->count();
        $recipesFailed = $missingMeals->where('preparation_status', PlannedMealRecipePreparationStatus::Failed->value)->count();
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
                'recipe_preparation' => [
                    'required' => $cookableMeals->count(),
                    'ready' => $recipesReady,
                    'preparing' => $recipesPreparing,
                    'failed' => $recipesFailed,
                    'unresolved' => $missingMeals->count(),
                ],
                'conversation' => $mealPlan->conversations->firstOrFail(),
                'shopping_categories' => collect(ShoppingListItemCategory::cases())
                    ->sortBy(fn (ShoppingListItemCategory $category) => $category->position())
                    ->map(fn (ShoppingListItemCategory $category) => [
                        'value' => $category->value,
                        'label' => $category->label(),
                    ])
                    ->values(),
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
                'automation' => [
                    'can_manage_integrations' => $request->user()->can('manageIntegrations', $mealPlan->team),
                    'pairing_code' => $request->session()->get('browser_pairing_code'),
                    'connections' => $mealPlan->team->browserConnections()
                        ->whereIn('status', [BrowserConnectionStatus::Pending, BrowserConnectionStatus::Active])
                        ->where('expires_at', '>', now())
                        ->latest()
                        ->get()
                        ->map(fn ($connection) => [
                            'uuid' => $connection->uuid,
                            'name' => $connection->name,
                            'status' => $connection->status->value,
                            'paired_at' => $connection->paired_at?->toIso8601String(),
                            'last_seen_at' => $connection->last_seen_at?->toIso8601String(),
                            'expires_at' => $connection->expires_at->toIso8601String(),
                        ]),
                    'runs' => $shoppingList?->automationRuns->map(fn ($run) => [
                        'uuid' => $run->uuid,
                        'status' => $run->status->value,
                        'shopping_list_revision' => $run->shopping_list_revision,
                        'retailer' => $run->retailer,
                        'browser_connection' => $run->browserConnection,
                        'progress' => $run->progress,
                        'pause_reason' => $run->pause_reason,
                        'error_message' => $run->error_message,
                        'current_url' => $run->current_url,
                        'expires_at' => $run->expires_at->toIso8601String(),
                        'started_at' => $run->started_at?->toIso8601String(),
                        'finished_at' => $run->finished_at?->toIso8601String(),
                        'approvals' => $run->approvals->map(fn ($approval) => [
                            'id' => $approval->id,
                            'risk_kind' => $approval->risk_kind,
                            'proposed_action' => $approval->proposed_action,
                            'consequence' => $approval->consequence,
                            'status' => $approval->status->value,
                            'expires_at' => $approval->expires_at->toIso8601String(),
                        ])->values()->all(),
                        'steps' => $run->steps->map(fn ($step) => [
                            'id' => $step->id,
                            'sequence' => $step->sequence,
                            'status' => $step->status->value,
                            'action_count' => count($step->actions ?? []),
                            'error_message' => $step->error_message,
                            'requested_at' => $step->requested_at?->toIso8601String(),
                            'executed_at' => $step->executed_at?->toIso8601String(),
                        ])->values()->all(),
                        'reconciliations' => $run->reconciliations->map(fn ($line) => [
                            'id' => $line->id,
                            'shopping_list_item_id' => $line->shopping_list_item_id,
                            'status' => $line->status->value,
                            'intended_name' => $line->intended_name,
                            'product_name' => $line->product_name,
                            'brand' => $line->brand,
                            'pack' => $line->pack,
                            'quantity' => $line->quantity,
                            'unit_price' => $line->unit_price,
                            'total_price' => $line->total_price,
                            'substitution_reason' => $line->substitution_reason,
                        ])->values()->all(),
                    ])->values()->all() ?? [],
                ],
            ],
        ]);
    }
}
