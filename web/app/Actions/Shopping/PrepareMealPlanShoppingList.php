<?php

namespace App\Actions\Shopping;

use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Enums\ShoppingListStatus;
use App\Jobs\FinishPreparingShoppingListJob;
use App\Models\MealPlan;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class PrepareMealPlanShoppingList
{
    public function __construct(private readonly PrepareMealPlanRecipes $prepareRecipes) {}

    public function handle(MealPlan $mealPlan, User $user): void
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot prepare a shopping list for this plan.');
        }

        if ($mealPlan->planning_confirmed_at === null) {
            throw ValidationException::withMessages([
                'meal_plan' => 'Confirm the meal plan before preparing its shopping list.',
            ]);
        }

        ShoppingList::query()->firstOrCreate(
            ['meal_plan_id' => $mealPlan->id],
            [
                'team_id' => $mealPlan->team_id,
                'created_by_user_id' => $user->id,
                'source_plan_revision' => $mealPlan->revision,
                'status' => ShoppingListStatus::Draft,
            ],
        );
        $this->prepareRecipes->handle($mealPlan, $user);
        FinishPreparingShoppingListJob::dispatch($mealPlan->id, $user->id);
    }
}
