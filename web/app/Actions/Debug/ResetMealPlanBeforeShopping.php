<?php

namespace App\Actions\Debug;

use App\Enums\PlannedMealType;
use App\Models\CartProductPlan;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\PlannedMealRecipePreparation;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetMealPlanBeforeShopping
{
    /**
     * @return array{
     *     meal_plan_id: int,
     *     deleted_shopping_lists: int,
     *     deleted_cart_product_plans: int,
     *     deleted_retailer_order_runs: int,
     *     detached_recipes: int,
     *     kept_recipes: bool
     * }
     */
    public function handle(MealPlan $mealPlan, bool $keepRecipes = false): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Resetting a meal plan before shopping is only available in local and testing.');
        }

        return DB::transaction(function () use ($mealPlan, $keepRecipes): array {
            $shoppingListIds = ShoppingList::query()
                ->where('meal_plan_id', $mealPlan->id)
                ->pluck('id');

            $deletedRuns = 0;
            $deletedPlans = 0;

            if ($shoppingListIds->isNotEmpty()) {
                $runs = RetailerOrderRun::query()
                    ->whereIn('shopping_list_id', $shoppingListIds)
                    ->get();

                foreach ($runs as $run) {
                    $run->steps()->delete();
                    $run->items()->delete();
                    $run->delete();
                    $deletedRuns++;
                }

                $plans = CartProductPlan::query()
                    ->whereIn('shopping_list_id', $shoppingListIds)
                    ->get();

                foreach ($plans as $plan) {
                    $plan->items()->delete();
                    $plan->delete();
                    $deletedPlans++;
                }

                ShoppingList::query()->whereIn('id', $shoppingListIds)->delete();
            }

            $detachedRecipes = 0;

            if (! $keepRecipes) {
                $mealIds = $mealPlan->plannedMeals()->pluck('id');
                PlannedMealRecipePreparation::query()
                    ->whereIn('planned_meal_id', $mealIds)
                    ->delete();

                $detachedRecipes = PlannedMeal::query()
                    ->where('meal_plan_id', $mealPlan->id)
                    ->whereNotNull('recipe_version_id')
                    ->whereIn('type', [PlannedMealType::Recipe->value, PlannedMealType::Custom->value])
                    ->update([
                        'recipe_version_id' => null,
                        'type' => PlannedMealType::Custom->value,
                    ]);
            }

            $mealPlan->update([
                'planning_confirmed_at' => null,
                'shopping_approved_by_user_id' => null,
                'shopping_approved_at' => null,
                'shopping_approval_fingerprint' => null,
                'confirmed_safety_context_hash' => null,
                'recipe_generation_requested_by_user_id' => null,
                'recipe_generation_status' => null,
                'recipe_generation_input_fingerprint' => null,
                'recipe_generation_input' => null,
                'recipe_generation_attempts' => 0,
                'recipe_generation_failure_code' => null,
                'recipe_generation_failure_message' => null,
                'recipe_generation_started_at' => null,
                'recipe_generation_completed_at' => null,
                'derived_data_stale_at' => null,
                'derived_data_stale_reason' => null,
            ]);

            return [
                'meal_plan_id' => $mealPlan->id,
                'deleted_shopping_lists' => $shoppingListIds->count(),
                'deleted_cart_product_plans' => $deletedPlans,
                'deleted_retailer_order_runs' => $deletedRuns,
                'detached_recipes' => $detachedRecipes,
                'kept_recipes' => $keepRecipes,
            ];
        });
    }
}
