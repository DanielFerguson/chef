<?php

namespace App\Actions\Shopping;

use App\Ai\Data\ShoppingListDraftRequest;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class BuildShoppingListDraftRequest
{
    public function __construct(
        private readonly ShoppingItemIdentity $identity,
        private readonly ShoppingRequirementQuantity $quantities,
    ) {}

    /** @param EloquentCollection<int, PlannedMeal> $plannedMeals */
    public function handle(MealPlan $mealPlan, EloquentCollection $plannedMeals): ShoppingListDraftRequest
    {
        $mealIds = $plannedMeals->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $constraints = [];
        $requirements = [];
        $sources = [];
        $nextRequirementId = 1;
        $team = $mealPlan->team()->with('constraints')->firstOrFail();

        foreach ($team->constraints->whereNull('person_id') as $constraint) {
            $constraints['team:'.$constraint->id] = [
                'owner' => $team->name,
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'details' => $constraint->details,
                'severity' => $constraint->severity,
                'planned_meal_ids' => $mealIds,
            ];
        }

        foreach ($plannedMeals as $plannedMeal) {
            foreach ($plannedMeal->mealSlot->participants as $person) {
                foreach ($person->constraints as $constraint) {
                    $key = 'person:'.$constraint->id;
                    $constraints[$key] ??= [
                        'owner' => $person->name,
                        'kind' => $constraint->kind->value,
                        'subject' => $constraint->subject,
                        'details' => $constraint->details,
                        'severity' => $constraint->severity,
                        'planned_meal_ids' => [],
                    ];
                    $constraints[$key]['planned_meal_ids'][] = $plannedMeal->id;
                    $constraints[$key]['planned_meal_ids'] = array_values(array_unique($constraints[$key]['planned_meal_ids']));
                }
            }

            $recipeVersion = $plannedMeal->recipeVersion;
            $scale = $recipeVersion !== null && $recipeVersion->servings > 0
                ? $plannedMeal->servings / $recipeVersion->servings
                : 1;

            foreach ($recipeVersion->ingredients as $ingredient) {
                if ($this->identity->isTapWater($ingredient->name)) {
                    continue;
                }

                $quantity = $ingredient->quantity === null ? null : $ingredient->quantity * $scale;
                $normalised = $this->quantities->normalise($ingredient->unit, $quantity);
                $requirementId = $nextRequirementId++;
                $requirements[] = [
                    'id' => $requirementId,
                    'planned_meal_id' => $plannedMeal->id,
                    'name' => $ingredient->name,
                    'quantity' => $normalised['quantity'],
                    'unit' => $normalised['unit'],
                    'optional' => (bool) $ingredient->optional,
                ];
                $sources[$requirementId] = [
                    'team_id' => $mealPlan->team_id,
                    'planned_meal_id' => $plannedMeal->id,
                    'recipe_ingredient_id' => $ingredient->id,
                    'ingredient_id' => $ingredient->ingredient_id,
                    'name' => $ingredient->name,
                    'canonical_key' => $this->identity->key($ingredient->name),
                    'quantity' => $normalised['quantity'],
                    'unit' => $normalised['unit'],
                    'dimension' => $normalised['dimension'],
                    'optional' => (bool) $ingredient->optional,
                ];
            }
        }

        return new ShoppingListDraftRequest(
            meals: $plannedMeals->map(fn (PlannedMeal $meal): array => [
                'id' => $meal->id,
                'title' => $meal->title,
                'date' => $meal->mealSlot->date->toDateString(),
                'kind' => $meal->mealSlot->kind->value,
                'servings' => $meal->servings,
            ])->all(),
            requirements: $requirements,
            sources: $sources,
            constraints: array_values($constraints),
        );
    }
}
