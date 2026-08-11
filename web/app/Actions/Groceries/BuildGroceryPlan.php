<?php

namespace App\Actions\Groceries;

use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\PlannedMealStatus;
use App\Models\Constraint;
use App\Models\GroceryPlan;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\RecipeIngredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuildGroceryPlan
{
    public function __construct(
        private readonly GroceryIngredientIdentity $identity,
        private readonly ResolveEffectiveRetailerPurchasePolicy $resolvePurchasePolicy,
    ) {}

    public function handle(MealPlan $mealPlan): GroceryPlan
    {
        return DB::transaction(function () use ($mealPlan): GroceryPlan {
            $lockedPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $lockedPlan->load([
                'team.constraints' => fn ($query) => $query->whereNull('person_id'),
                'plannedMeals' => fn ($query) => $query
                    ->where('status', PlannedMealStatus::Planned->value)
                    ->with([
                        'mealSlot.participants.constraints',
                        'recipeVersion.ingredients.ingredient',
                    ]),
            ]);

            if ($lockedPlan->planning_confirmed_at === null || $lockedPlan->derived_data_stale_at !== null) {
                throw ValidationException::withMessages([
                    'meal_plan' => 'Only a current approved meal plan can prepare grocery requirements.',
                ]);
            }

            $recipeFingerprint = $this->recipeFingerprint($lockedPlan->plannedMeals);
            $effectivePolicy = $this->resolvePurchasePolicy->handle($lockedPlan);
            $inputFingerprint = hash('sha256', json_encode([
                'meal_plan_id' => $lockedPlan->id,
                'revision' => $lockedPlan->revision,
                'confirmed_safety_context_hash' => $lockedPlan->confirmed_safety_context_hash,
                'recipe_fingerprint' => $recipeFingerprint,
                'pantry_policy' => 'purchase_all_v1',
                'purchase_policy_fingerprint' => $effectivePolicy['fingerprint'],
            ], JSON_THROW_ON_ERROR));
            $existing = GroceryPlan::query()
                ->whereBelongsTo($lockedPlan)
                ->where('input_fingerprint', $inputFingerprint)
                ->first();

            if ($existing !== null) {
                $this->attachToLatestBasketRun($lockedPlan, $existing);

                return $existing->load('requirements.sources');
            }

            GroceryPlan::query()
                ->whereBelongsTo($lockedPlan)
                ->whereNull('superseded_at')
                ->update([
                    'status' => GroceryPlanStatus::Superseded->value,
                    'superseded_at' => now(),
                ]);

            $groceryPlan = GroceryPlan::query()->create([
                'team_id' => $lockedPlan->team_id,
                'meal_plan_id' => $lockedPlan->id,
                'version' => ((int) GroceryPlan::query()->whereBelongsTo($lockedPlan)->max('version')) + 1,
                'status' => GroceryPlanStatus::Building,
                'input_fingerprint' => $inputFingerprint,
                'recipe_fingerprint' => $recipeFingerprint,
                'purchase_policy_snapshot' => $effectivePolicy['snapshot'],
                'purchase_policy_fingerprint' => $effectivePolicy['fingerprint'],
                'effective_basket_target_cents' => $effectivePolicy['basket_target_cents'],
            ]);

            foreach ($this->requirements($lockedPlan) as $requirementData) {
                $sources = $requirementData['sources'];
                unset($requirementData['sources']);
                $requirement = $groceryPlan->requirements()->create([
                    'team_id' => $groceryPlan->team_id,
                    ...$requirementData,
                ]);

                foreach ($sources as $source) {
                    $requirement->sources()->create([
                        'team_id' => $groceryPlan->team_id,
                        ...$source,
                    ]);
                }
            }

            $groceryPlan->update([
                'status' => GroceryPlanStatus::Ready,
                'built_at' => now(),
            ]);

            $this->attachToLatestBasketRun($lockedPlan, $groceryPlan);

            return $groceryPlan->load('requirements.sources');
        });
    }

    private function attachToLatestBasketRun(MealPlan $mealPlan, GroceryPlan $groceryPlan): void
    {
        $run = $mealPlan->basketRuns()->latest('id')->first();

        if ($run === null || $run->grocery_plan_id !== null) {
            return;
        }

        $run->update([
            'grocery_plan_id' => $groceryPlan->id,
            'status' => match ($run->status) {
                BasketRunStatus::WaitingForConnection => BasketRunStatus::WaitingForConnection,
                BasketRunStatus::ReauthenticationRequired => BasketRunStatus::ReauthenticationRequired,
                default => BasketRunStatus::DiscoveringProducts,
            },
        ]);
    }

    /** @param Collection<int, PlannedMeal> $plannedMeals */
    private function recipeFingerprint(Collection $plannedMeals): string
    {
        $recipes = $plannedMeals
            ->filter(fn (PlannedMeal $meal): bool => $meal->recipeVersion !== null)
            ->map(fn (PlannedMeal $meal): array => [
                'planned_meal_id' => $meal->id,
                'planned_servings' => $meal->servings,
                'recipe_version_id' => $meal->recipe_version_id,
                'recipe_servings' => $meal->recipeVersion->servings,
                'ingredients' => $meal->recipeVersion->ingredients
                    ->map(fn (RecipeIngredient $ingredient): array => [
                        'id' => $ingredient->id,
                        'name' => $ingredient->name,
                        'quantity' => $ingredient->quantity,
                        'unit' => $ingredient->unit,
                        'preparation' => $ingredient->preparation,
                        'optional' => $ingredient->optional,
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortBy('planned_meal_id')
            ->values()
            ->all();

        return hash('sha256', json_encode($recipes, JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    private function requirements(MealPlan $mealPlan): array
    {
        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];

        foreach ($mealPlan->plannedMeals as $plannedMeal) {
            $recipeVersion = $plannedMeal->recipeVersion;
            if ($recipeVersion === null) {
                continue;
            }

            $servingFactor = $plannedMeal->servings / max(0.25, $recipeVersion->servings);
            $constraints = $this->constraintsFor($mealPlan, $plannedMeal);

            foreach ($recipeVersion->ingredients as $ingredient) {
                if ($ingredient->optional || $this->identity->isWater($ingredient->name)) {
                    continue;
                }

                $ingredientName = $ingredient->ingredient_id === null
                    ? $ingredient->name
                    : $ingredient->ingredient->normalized_name;
                $identity = $this->identity->resolve(
                    $ingredientName,
                    $ingredient->preparation,
                    $ingredient->quantity,
                    $ingredient->unit,
                );
                $key = $identity['fingerprint'];
                $retailComparable = in_array($identity['unit'], ['g', 'ml', 'each'], true);
                $scaledQuantity = $identity['quantity'] === null || ! $retailComparable
                    ? null
                    : $identity['quantity'] * $servingFactor;

                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'ingredient_id' => $ingredient->ingredient_id,
                        'status' => GroceryRequirementStatus::Pending,
                        'display_name' => $ingredient->name,
                        'normalized_name' => $identity['normalized_name'],
                        'normalized_form' => $identity['normalized_form'],
                        'quantity' => $scaledQuantity,
                        'unit' => $retailComparable ? $identity['unit'] : null,
                        'quantity_unknown' => $scaledQuantity === null,
                        'fingerprint' => $identity['fingerprint'],
                        'search_queries' => $this->searchQueries(
                            $ingredient->name,
                            $identity['normalized_form'],
                        ),
                        'applicable_constraints' => $constraints,
                        'sources' => [],
                    ];
                } else {
                    if ($scaledQuantity === null) {
                        $grouped[$key]['quantity_unknown'] = true;
                        $grouped[$key]['quantity'] = null;
                    } elseif (! $grouped[$key]['quantity_unknown']) {
                        $grouped[$key]['quantity'] += $scaledQuantity;
                    }

                    $grouped[$key]['applicable_constraints'] = collect([
                        ...$grouped[$key]['applicable_constraints'],
                        ...$constraints,
                    ])->unique(fn (array $constraint): string => json_encode($constraint, JSON_THROW_ON_ERROR))
                        ->values()
                        ->all();
                }

                $grouped[$key]['sources'][] = [
                    'planned_meal_id' => $plannedMeal->id,
                    'recipe_version_id' => $recipeVersion->id,
                    'recipe_ingredient_id' => $ingredient->id,
                    'source_name' => $ingredient->name,
                    'preparation' => $ingredient->preparation,
                    'source_quantity' => $ingredient->quantity,
                    'scaled_quantity' => $scaledQuantity,
                    'serving_factor' => $servingFactor,
                    'unit' => $identity['unit'],
                ];
            }
        }

        return array_values(collect($grouped)
            ->sortBy(fn (array $requirement): string => $requirement['normalized_name'].'|'.($requirement['normalized_form'] ?? '').'|'.($requirement['unit'] ?? ''))
            ->values()
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private function constraintsFor(MealPlan $mealPlan, PlannedMeal $plannedMeal): array
    {
        return array_values($mealPlan->team->constraints
            ->concat($plannedMeal->mealSlot->participants->flatMap(
                fn ($person) => $person->constraints,
            ))
            ->unique('id')
            ->map(fn (Constraint $constraint): array => [
                'constraint_id' => $constraint->id,
                'kind' => $constraint->kind->value,
                'subject' => $constraint->subject,
                'details' => $constraint->details,
                'severity' => $constraint->severity,
            ])
            ->sortBy(fn (array $constraint): string => $constraint['kind'].'|'.$constraint['subject'])
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function searchQueries(string $name, ?string $form): array
    {
        return array_values(collect([
            Str::squish(trim($name.' '.($form ?? ''))),
            Str::squish($name),
            Str::of($name)->before(',')->squish()->toString(),
        ])->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all());
    }
}
