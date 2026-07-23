<?php

namespace App\Actions\Shopping;

use App\Actions\Automation\BuildCartPreparationPreflight;
use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\RetailerOrderRunStatus;
use App\Enums\ShoppingListItemCategory;
use App\Models\Budget;
use App\Models\CartProductPlan;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\Retailer;
use App\Models\User;
use App\Retailer\RetailerOrderRunView;

class BuildShoppingWorkspace
{
    public function __construct(
        private readonly MealPlanSafetyContext $safetyContext,
        private readonly AssessMealPlanReadiness $assessReadiness,
        private readonly BuildCartPreparationPreflight $buildCartPreparationPreflight,
        private readonly RetailerOrderRunView $retailerOrderRunView,
    ) {}

    public function isEligible(MealPlan $mealPlan): bool
    {
        if ($mealPlan->planning_confirmed_at === null) {
            return false;
        }

        $fingerprint = $this->safetyContext->fingerprint($mealPlan);

        return is_string($mealPlan->confirmed_safety_context_hash)
            && hash_equals($mealPlan->confirmed_safety_context_hash, $fingerprint);
    }

    /**
     * @return array{
     *     plan: array<string, mixed>,
     *     shopping_list: mixed,
     *     missing_meals: list<array<string, mixed>>,
     *     recipe_preparation: array<string, int>,
     *     shopping_categories: list<array{value: string, label: string}>,
     *     retailers: mixed,
     *     budget: array<string, mixed>,
     *     cart_automation: array<string, mixed>,
     *     retailer_order_run: mixed
     * }
     */
    public function handle(MealPlan $mealPlan, User $user): array
    {
        $mealPlan->loadMissing([
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
            ->map(fn (PlannedMeal $meal): array => [
                'id' => $meal->id,
                'title' => $meal->title,
                'date' => $meal->mealSlot->date->toDateString(),
                'kind' => $meal->mealSlot->kind->value,
                'preparation_id' => null,
                'preparation_status' => $mealPlan->recipe_generation_status instanceof MealPlanRecipeGenerationStatus
                    ? $mealPlan->recipe_generation_status->value
                    : 'not_started',
                'failure_message' => $mealPlan->recipe_generation_failure_message,
            ]);
        $cookableMeals = $mealPlan->plannedMeals
            ->filter(fn ($meal) => $meal->getRawOriginal('status') === PlannedMealStatus::Planned->value
                && in_array($meal->getRawOriginal('type'), [PlannedMealType::Recipe->value, PlannedMealType::Custom->value], true));
        $recipesReady = $cookableMeals->whereNotNull('recipe_version_id')->count();
        $recipesPreparing = $missingMeals->whereIn('preparation_status', [
            MealPlanRecipeGenerationStatus::Pending->value,
            MealPlanRecipeGenerationStatus::Processing->value,
        ])->count();
        $recipesFailed = $missingMeals->where('preparation_status', MealPlanRecipeGenerationStatus::Failed->value)->count();
        $householdBudget = $mealPlan->team->budgets()->whereNull('meal_plan_id')->latest()->first();
        $planBudget = Budget::query()->where('team_id', $mealPlan->team_id)->where('meal_plan_id', $mealPlan->id)->first();
        $projectedTotal = $shoppingList?->items
            ->filter(fn ($item) => $item->included && ! $item->in_pantry)
            ->sum(fn ($item): float => (float) ($item->estimated_price ?? 0)) ?? 0;
        $unmatchedItems = $shoppingList?->items
            ->filter(fn ($item) => $item->included && ! $item->in_pantry && $item->productMatch === null && $item->estimated_price === null)
            ->count() ?? 0;

        $effectiveBudget = $planBudget !== null ? $planBudget->amount : $householdBudget?->amount;
        $woolworths = Retailer::query()->where('slug', 'woolworths')->first();
        $retailerConnection = $woolworths === null
            ? null
            : $mealPlan->team->retailerConnections()
                ->where('retailer_id', $woolworths->id)
                ->where('owner_user_id', $user->id)
                ->first();
        $retailerOrderRun = $shoppingList === null
            ? null
            : $shoppingList->retailerOrderRuns()
                ->whereNotIn('status', [
                    RetailerOrderRunStatus::Placed->value,
                    RetailerOrderRunStatus::Failed->value,
                    RetailerOrderRunStatus::Cancelled->value,
                ])
                ->latest('id')
                ->first();
        $cartItemCount = $shoppingList?->items
            ->filter(fn ($item) => $item->included && ! $item->in_pantry)
            ->count() ?? 0;
        $currentShoppingRevision = $shoppingList?->revisions
            ->firstWhere('revision', $shoppingList->revision);
        $readiness = $this->assessReadiness->handle($mealPlan);
        $cartReadinessReasons = collect([
            $shoppingList === null ? 'Generate the shopping list first.' : null,
            $shoppingList !== null && $shoppingList->generation_status->value !== 'ready' ? 'Wait for the shopping list to finish preparing.' : null,
            $shoppingList !== null && $shoppingList->stale_at !== null ? 'Refresh the shopping list from the current meal plan.' : null,
            $missingMeals->isNotEmpty() ? 'Resolve every planned meal’s ingredients.' : null,
            $cartItemCount === 0 ? 'Add at least one included item that is not already in the pantry.' : null,
            $currentShoppingRevision === null ? 'Save a current shopping-list revision.' : null,
        ])->filter()->values();
        $cartPreflight = $shoppingList !== null && $currentShoppingRevision !== null
            ? $this->buildCartPreparationPreflight->handle($shoppingList, $currentShoppingRevision)
            : [
                'total_items' => 0,
                'matched_items' => 0,
                'automatic_search_items' => 0,
                'automatic_search_item_names' => [],
                'requires_exact_matches' => false,
                'can_prepare' => false,
                'constraints' => [],
            ];
        $cartProductPlan = $shoppingList === null || $currentShoppingRevision === null || $woolworths === null
            ? null
            : CartProductPlan::query()
                ->where('shopping_list_id', $shoppingList->id)
                ->where('shopping_list_revision_id', $currentShoppingRevision->id)
                ->where('retailer_id', $woolworths->id)
                ->with('items')
                ->latest('id')
                ->first();

        return [
            'plan' => [
                'id' => $mealPlan->id,
                'title' => $mealPlan->title,
                'starts_on' => $mealPlan->starts_on->toDateString(),
                'ends_on' => $mealPlan->ends_on->toDateString(),
                'revision' => $mealPlan->revision,
                'planning_confirmed_at' => $mealPlan->planning_confirmed_at?->toIso8601String(),
                'shopping_approved_at' => $mealPlan->shopping_approved_at?->toIso8601String(),
                'safety_review_required' => $readiness['safety_review_required'] || $readiness['ready_for_safety_confirmation'],
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
            'shopping_categories' => collect(ShoppingListItemCategory::cases())
                ->sortBy(fn (ShoppingListItemCategory $category) => $category->position())
                ->map(fn (ShoppingListItemCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ])
                ->values(),
            'retailers' => Retailer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'slug']),
            'budget' => [
                'household_default' => $householdBudget?->amount,
                'plan_override' => $planBudget?->amount,
                'effective' => $effectiveBudget,
                'projected_total' => round($projectedTotal, 2),
                'unmatched_items' => $unmatchedItems,
                'currency' => 'AUD',
            ],
            'cart_automation' => [
                'approved' => $mealPlan->shopping_approved_at !== null,
                'connection_enabled' => (bool) config('automation.connection_enabled'),
                'cart_mutation_enabled' => (bool) config('automation.cart_mutation_enabled'),
                'normal_app_sync_proven' => (bool) config('automation.normal_app_sync_proven'),
                'ready' => $cartReadinessReasons->isEmpty(),
                'readiness_reasons' => $cartReadinessReasons,
                'shopping_list_revision_id' => $currentShoppingRevision?->id,
                'preflight' => $cartPreflight,
                'product_plan' => $cartProductPlan === null ? null : [
                    'id' => $cartProductPlan->id,
                    'status' => $cartProductPlan->status->value,
                    'exact_items' => (int) ($cartProductPlan->snapshot['exact_items'] ?? 0),
                    'ambiguous_items' => (int) ($cartProductPlan->snapshot['ambiguous_items'] ?? 0),
                    'unresolved_items' => (int) ($cartProductPlan->snapshot['unresolved_items'] ?? 0),
                    'discovery_failed' => (bool) ($cartProductPlan->snapshot['discovery_failed'] ?? false),
                    'discovery_ms' => is_numeric($cartProductPlan->snapshot['discovery_ms'] ?? null)
                        ? (float) $cartProductPlan->snapshot['discovery_ms']
                        : null,
                    'items' => $cartProductPlan->items->map(fn ($item) => [
                        'id' => $item->id,
                        'shopping_list_item_id' => $item->shopping_list_item_id,
                        'name' => (string) ($item->requirement_snapshot['name'] ?? 'Shopping item'),
                        'status' => $item->status->value,
                        'decision_reason' => $item->decision_reason,
                        'selected_product' => $item->selected_product,
                        'candidates' => collect($item->candidates ?? [])->take(5)->values(),
                    ])->values(),
                ],
                'connection' => $retailerConnection === null ? null : [
                    'id' => $retailerConnection->id,
                    'status' => $retailerConnection->status->value,
                    'owner_user_id' => $retailerConnection->owner_user_id,
                    'last_verified_at' => $retailerConnection->last_verified_at?->toIso8601String(),
                ],
            ],
            'retailer_order_run' => $retailerOrderRun === null
                ? null
                : $this->retailerOrderRunView->make($retailerOrderRun),
        ];
    }
}
