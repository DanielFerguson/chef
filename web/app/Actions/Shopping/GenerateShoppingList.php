<?php

namespace App\Actions\Shopping;

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\MealPlan;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateShoppingList
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly RecordMealPlanMilestone $recordMilestone,
    ) {}

    public function handle(MealPlan $mealPlan, User $user): ShoppingList
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot generate a shopping list for this plan.');
        }

        if ($mealPlan->planning_confirmed_at === null) {
            throw ValidationException::withMessages([
                'meal_plan' => 'Confirm the meal plan before generating its shopping list.',
            ]);
        }

        return DB::transaction(function () use ($mealPlan, $user): ShoppingList {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $shoppingList = ShoppingList::query()->firstOrCreate(
                ['meal_plan_id' => $mealPlan->id],
                [
                    'team_id' => $mealPlan->team_id,
                    'created_by_user_id' => $user->id,
                    'source_plan_revision' => $mealPlan->revision,
                    'status' => ShoppingListStatus::Draft,
                ],
            );

            if ($shoppingList->revision > 0
                && $shoppingList->source_plan_revision === $mealPlan->revision
                && $shoppingList->stale_at === null) {
                return $shoppingList;
            }

            $plannedMeals = $mealPlan->plannedMeals()
                ->whereNotNull('recipe_version_id')
                ->where('status', 'planned')
                ->with(['recipeVersion.ingredients', 'mealSlot'])
                ->get();
            $requirements = [];

            foreach ($plannedMeals as $plannedMeal) {
                $recipeVersion = $plannedMeal->recipeVersion;

                if ($recipeVersion === null) {
                    continue;
                }

                $scale = $recipeVersion->servings > 0
                    ? $plannedMeal->servings / $recipeVersion->servings
                    : 1;

                foreach ($recipeVersion->ingredients as $recipeIngredient) {
                    [$unit, $quantity] = $this->normaliseUnitAndQuantity(
                        $recipeIngredient->unit,
                        $recipeIngredient->quantity === null ? null : $recipeIngredient->quantity * $scale,
                    );
                    $normalisedName = Str::of($recipeIngredient->name)->squish()->lower()->toString();
                    $key = $normalisedName.'|'.($unit ?? '');

                    if (! isset($requirements[$key])) {
                        $requirements[$key] = [
                            'ingredient_id' => $recipeIngredient->ingredient_id,
                            'name' => $recipeIngredient->name,
                            'normalized_name' => $normalisedName,
                            'quantity' => $quantity,
                            'unit' => $unit,
                            'optional' => $recipeIngredient->optional,
                            'quantity_known' => $quantity !== null,
                            'sources' => [],
                        ];
                    } else {
                        $requirements[$key]['optional'] = $requirements[$key]['optional'] && $recipeIngredient->optional;
                        $requirements[$key]['quantity_known'] = $requirements[$key]['quantity_known'] && $quantity !== null;

                        if ($requirements[$key]['quantity_known']) {
                            $requirements[$key]['quantity'] += $quantity;
                        } else {
                            $requirements[$key]['quantity'] = null;
                        }
                    }

                    $requirements[$key]['sources'][] = [
                        'team_id' => $mealPlan->team_id,
                        'planned_meal_id' => $plannedMeal->id,
                        'recipe_ingredient_id' => $recipeIngredient->id,
                        'quantity' => $quantity,
                        'unit' => $unit,
                    ];
                }
            }

            $shoppingList->items()->where('source_kind', ShoppingListItemSourceKind::Recipe)->delete();
            ksort($requirements);
            $position = 1;

            foreach ($requirements as $requirement) {
                $sources = $requirement['sources'];
                unset($requirement['sources'], $requirement['quantity_known']);
                $item = $shoppingList->items()->create([
                    ...$requirement,
                    'team_id' => $mealPlan->team_id,
                    'created_by_user_id' => $user->id,
                    'source_kind' => ShoppingListItemSourceKind::Recipe,
                    'included' => true,
                    'position' => $position++,
                ]);
                $item->sources()->createMany($sources);
            }

            foreach ($shoppingList->items()->whereNot('source_kind', ShoppingListItemSourceKind::Recipe)->orderBy('position')->get() as $manualItem) {
                $manualItem->update(['position' => $position++]);
            }

            $currentCustomMealIds = $mealPlan->plannedMeals()
                ->where('status', 'planned')
                ->where('type', 'custom')
                ->whereNull('recipe_version_id')
                ->pluck('id');
            $shoppingList->mealResolutions()->whereNotIn('planned_meal_id', $currentCustomMealIds)->delete();
            $obsoleteMealItems = $shoppingList->items()
                ->where('source_kind', ShoppingListItemSourceKind::PlannedMeal)
                ->whereHas('sources', fn ($query) => $query->whereNotIn('planned_meal_id', $currentCustomMealIds))
                ->get();

            foreach ($obsoleteMealItems as $obsoleteMealItem) {
                $obsoleteMealItem->delete();
            }

            $shoppingList->update([
                'source_plan_revision' => $mealPlan->revision,
                'status' => ShoppingListStatus::Draft,
                'completed_at' => null,
                'stale_at' => null,
                'stale_reason' => null,
                'stale_diff' => null,
            ]);
            $this->recordRevision->handle($shoppingList, $user, 'Generated shopping list from confirmed plan');
            $this->recordMilestone->handle($mealPlan, $user, MealPlanMilestoneKind::ShoppingListGenerated);

            return $shoppingList->refresh();
        });
    }

    /** @return array{0: string|null, 1: float|null} */
    private function normaliseUnitAndQuantity(?string $unit, ?float $quantity): array
    {
        if ($unit === null || trim($unit) === '') {
            return [null, $quantity === null ? null : round($quantity, 3)];
        }

        $normalised = Str::of($unit)->squish()->lower()->toString();
        $canonical = match ($normalised) {
            'gram', 'grams', 'g' => 'g',
            'kilogram', 'kilograms', 'kg' => 'kg',
            'millilitre', 'millilitres', 'milliliter', 'milliliters', 'ml' => 'ml',
            'litre', 'litres', 'liter', 'liters', 'l' => 'l',
            'tablespoon', 'tablespoons', 'tbsp' => 'tbsp',
            'teaspoon', 'teaspoons', 'tsp' => 'tsp',
            'piece', 'pieces', 'each' => 'each',
            default => $normalised,
        };

        if ($canonical === 'kg') {
            return ['g', $quantity === null ? null : round($quantity * 1000, 3)];
        }

        if ($canonical === 'l') {
            return ['ml', $quantity === null ? null : round($quantity * 1000, 3)];
        }

        return [$canonical, $quantity === null ? null : round($quantity, 3)];
    }
}
