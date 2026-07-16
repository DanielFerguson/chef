<?php

namespace App\Actions\Recipes;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Ai\Contracts\RecipeDrafter;
use App\Ai\Data\RecipeDraftRequest;
use App\Enums\PlannedMealRecipePreparationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreparationNoticeKind;
use App\Models\PlannedMeal;
use App\Models\PlannedMealRecipePreparation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class MaterializePlannedMealRecipe
{
    public function __construct(
        private readonly RecipeDrafter $drafter,
        private readonly CreateRecipe $createRecipe,
        private readonly RecordMealPlanRevision $recordRevision,
    ) {}

    public function handle(PlannedMealRecipePreparation $preparation): PlannedMealRecipePreparation
    {
        $preparation = DB::transaction(function () use ($preparation): PlannedMealRecipePreparation {
            $locked = PlannedMealRecipePreparation::query()->lockForUpdate()->findOrFail($preparation->id);

            if (in_array($locked->status, [
                PlannedMealRecipePreparationStatus::Completed,
                PlannedMealRecipePreparationStatus::Cancelled,
            ], true)) {
                return $locked;
            }

            $plannedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($locked->planned_meal_id);

            if ($plannedMeal->recipe_version_id !== null) {
                $locked->update([
                    'recipe_version_id' => $plannedMeal->recipe_version_id,
                    'status' => PlannedMealRecipePreparationStatus::Completed,
                    'failure_code' => null,
                    'failure_message' => null,
                    'completed_at' => now(),
                ]);

                return $locked->refresh();
            }

            if ($plannedMeal->status !== PlannedMealStatus::Planned || $plannedMeal->type !== PlannedMealType::Custom) {
                $locked->update([
                    'status' => PlannedMealRecipePreparationStatus::Cancelled,
                    'failure_code' => null,
                    'failure_message' => null,
                    'completed_at' => now(),
                ]);

                return $locked->refresh();
            }

            $locked->update([
                'status' => PlannedMealRecipePreparationStatus::Processing,
                'attempts' => $locked->attempts + 1,
                'failure_code' => null,
                'failure_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);

            return $locked->refresh();
        });

        if (in_array($preparation->status, [
            PlannedMealRecipePreparationStatus::Completed,
            PlannedMealRecipePreparationStatus::Cancelled,
        ], true)) {
            return $preparation;
        }

        try {
            $draft = $this->drafter->draft(RecipeDraftRequest::fromArray($preparation->input));
            $validated = Validator::make($draft->toArray(), [
                'title' => ['required', 'string', 'max:160'],
                'summary' => ['nullable', 'string', 'max:2000'],
                'servings' => ['required', 'numeric', 'min:0.25', 'max:999'],
                'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'ingredients' => ['required', 'array', 'min:1'],
                'ingredients.*.name' => ['required', 'string', 'max:255'],
                'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0'],
                'ingredients.*.unit' => ['nullable', 'string', 'max:80'],
                'ingredients.*.preparation' => ['nullable', 'string', 'max:255'],
                'ingredients.*.optional' => ['required', 'boolean'],
                'steps' => ['required', 'array', 'min:1'],
                'steps.*.instruction' => ['required', 'string'],
                'steps.*.timer_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'equipment' => ['present', 'array'],
                'equipment.*' => ['required', 'string', 'max:255'],
                'notices' => ['present', 'array'],
                'notices.*.kind' => ['required', 'string', 'in:'.collect(PreparationNoticeKind::cases())->pluck('value')->join(',')],
                'notices.*.instruction' => ['required', 'string'],
                'notices.*.lead_minutes' => ['nullable', 'integer', 'min:0'],
            ])->validate();

            return DB::transaction(function () use ($preparation, $validated): PlannedMealRecipePreparation {
                $lockedPreparation = PlannedMealRecipePreparation::query()->lockForUpdate()->findOrFail($preparation->id);
                $plannedMeal = PlannedMeal::query()->with('mealPlan')->lockForUpdate()->findOrFail($lockedPreparation->planned_meal_id);

                if ($plannedMeal->status !== PlannedMealStatus::Planned || $plannedMeal->type !== PlannedMealType::Custom) {
                    $lockedPreparation->update([
                        'status' => PlannedMealRecipePreparationStatus::Cancelled,
                        'failure_code' => null,
                        'failure_message' => null,
                        'completed_at' => now(),
                    ]);

                    return $lockedPreparation->refresh();
                }

                if ($plannedMeal->recipe_version_id !== null) {
                    $lockedPreparation->update([
                        'recipe_version_id' => $plannedMeal->recipe_version_id,
                        'status' => PlannedMealRecipePreparationStatus::Completed,
                        'completed_at' => now(),
                    ]);

                    return $lockedPreparation->refresh();
                }

                $user = User::query()->findOrFail($lockedPreparation->requested_by_user_id);
                $recipe = $this->createRecipe->handle(
                    $plannedMeal->mealPlan->team,
                    $user,
                    $validated['title'],
                    $validated['summary'] ?? null,
                    (float) $validated['servings'],
                    $validated['prep_minutes'] ?? null,
                    $validated['cook_minutes'] ?? null,
                    $validated['ingredients'],
                    $validated['steps'],
                    $validated['equipment'],
                    $validated['notices'],
                    notes: 'Chef-prepared draft for meal plan '.$plannedMeal->meal_plan_id.'.',
                    idempotencyKey: hash('sha256', 'planned-meal-recipe|'.$plannedMeal->team_id.'|'.$plannedMeal->id.'|'.$lockedPreparation->input_fingerprint),
                );
                $version = $recipe->latestVersion;

                $plannedMeal->update([
                    'recipe_version_id' => $version->id,
                    'type' => PlannedMealType::Recipe,
                    'title' => $version->title,
                    'summary' => $version->summary,
                    'estimated_minutes' => ($version->prep_minutes ?? 0) + ($version->cook_minutes ?? 0),
                ]);
                $this->recordRevision->handle(
                    $plannedMeal->mealPlan,
                    $user,
                    'Prepared the recipe for '.$plannedMeal->title.'.',
                    [
                        'planned_meal_id' => $plannedMeal->id,
                        'recipe_version_id' => $version->id,
                        'recipe_preparation_id' => $lockedPreparation->id,
                    ],
                );
                $lockedPreparation->update([
                    'recipe_version_id' => $version->id,
                    'status' => PlannedMealRecipePreparationStatus::Completed,
                    'failure_code' => null,
                    'failure_message' => null,
                    'completed_at' => now(),
                ]);

                return $lockedPreparation->refresh();
            });
        } catch (Throwable $exception) {
            $outcome = DB::transaction(function () use ($preparation, $exception): PlannedMealRecipePreparation {
                $lockedPreparation = PlannedMealRecipePreparation::query()->lockForUpdate()->findOrFail($preparation->id);
                $plannedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($lockedPreparation->planned_meal_id);

                if (in_array($lockedPreparation->status, [
                    PlannedMealRecipePreparationStatus::Completed,
                    PlannedMealRecipePreparationStatus::Cancelled,
                ], true)) {
                    return $lockedPreparation;
                }

                if ($plannedMeal->recipe_version_id !== null) {
                    $lockedPreparation->update([
                        'recipe_version_id' => $plannedMeal->recipe_version_id,
                        'status' => PlannedMealRecipePreparationStatus::Completed,
                        'failure_code' => null,
                        'failure_message' => null,
                        'completed_at' => now(),
                    ]);

                    return $lockedPreparation->refresh();
                }

                if ($plannedMeal->status !== PlannedMealStatus::Planned || $plannedMeal->type !== PlannedMealType::Custom) {
                    $lockedPreparation->update([
                        'status' => PlannedMealRecipePreparationStatus::Cancelled,
                        'failure_code' => null,
                        'failure_message' => null,
                        'completed_at' => now(),
                    ]);

                    return $lockedPreparation->refresh();
                }

                $lockedPreparation->update([
                    'status' => PlannedMealRecipePreparationStatus::Failed,
                    'failure_code' => Str::snake(class_basename($exception)),
                    'failure_message' => 'Chef could not prepare this recipe.',
                    'completed_at' => null,
                ]);

                return $lockedPreparation->refresh();
            });

            if ($outcome->status !== PlannedMealRecipePreparationStatus::Failed) {
                return $outcome;
            }

            throw $exception;
        }
    }
}
