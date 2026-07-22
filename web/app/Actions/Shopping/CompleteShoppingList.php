<?php

namespace App\Actions\Shopping;

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\ShoppingListStatus;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteShoppingList
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly RecordMealPlanMilestone $recordMilestone,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    public function handle(ShoppingList $shoppingList, User $user, int $expectedRevision): ShoppingList
    {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot complete this shopping list.');
        }

        return DB::transaction(function () use ($shoppingList, $user, $expectedRevision): ShoppingList {
            $shoppingList = $this->ensureEditable->handle($shoppingList);
            $resolvedMealIds = $shoppingList->mealResolutions()->pluck('planned_meal_id');
            $mealsWithoutIngredients = $shoppingList->mealPlan->plannedMeals()
                ->where('status', 'planned')
                ->where('type', 'custom')
                ->whereNull('recipe_version_id')
                ->whereNotIn('id', $resolvedMealIds)
                ->count();

            if ($mealsWithoutIngredients > 0) {
                throw ValidationException::withMessages([
                    'shopping_list' => 'Add ingredients for every planned meal before completing this shopping list.',
                ]);
            }

            $remaining = $shoppingList->items()
                ->where('included', true)
                ->where('in_pantry', false)
                ->where('checked', false)
                ->whereNull('ordered_at')
                ->count();

            if ($remaining > 0) {
                throw ValidationException::withMessages(['shopping_list' => 'Buy, order, or mark every included item as already in the pantry first.']);
            }

            $shoppingList->update(['status' => ShoppingListStatus::Completed, 'completed_at' => now()]);
            $this->recordRevision->handle($shoppingList, $user, 'Completed shopping list', $expectedRevision);
            $this->recordMilestone->handle($shoppingList->mealPlan, $user, MealPlanMilestoneKind::ShoppingCompleted);

            return $shoppingList->refresh();
        });
    }
}
