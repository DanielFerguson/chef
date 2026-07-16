<?php

namespace App\Actions\Recipes;

use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\PlannedMealRecipePreparation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class PrepareMealPlanRecipes
{
    public function __construct(private readonly PreparePlannedMealRecipe $prepareMeal) {}

    /** @return Collection<int, PlannedMealRecipePreparation> */
    public function handle(MealPlan $mealPlan, User $user): Collection
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot prepare recipes for this plan.');
        }

        $resolvedMealIds = $mealPlan->shoppingList?->mealResolutions()->pluck('planned_meal_id') ?? collect();

        return $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $resolvedMealIds))
            ->get()
            ->map(fn (PlannedMeal $meal) => $this->prepareMeal->handle($meal, $user))
            ->filter()
            ->values();
    }
}
