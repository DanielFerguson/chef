<?php

namespace App\Actions\Recipes;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Actions\MealPlans\MealPlanShoppingApprovalContext;
use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Enums\PreparationNoticeKind;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MaterializeMealPlanRecipes
{
    public function __construct(
        private readonly MealPlanRecipeDrafter $drafter,
        private readonly BuildMealPlanRecipeDraftRequest $buildRequest,
        private readonly CreateRecipe $createRecipe,
        private readonly RecordMealPlanRevision $recordRevision,
        private readonly MealPlanSafetyContext $safetyContext,
        private readonly MealPlanShoppingApprovalContext $approvalContext,
    ) {}

    public function handle(MealPlan $mealPlan): MealPlan
    {
        $claimed = DB::transaction(function () use ($mealPlan): ?array {
            $locked = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);

            if ($locked->recipe_generation_status !== MealPlanRecipeGenerationStatus::Pending) {
                return null;
            }

            $locked->update([
                'recipe_generation_status' => MealPlanRecipeGenerationStatus::Processing,
                'recipe_generation_attempts' => $locked->recipe_generation_attempts + 1,
                'recipe_generation_failure_code' => null,
                'recipe_generation_failure_message' => null,
                'recipe_generation_started_at' => now(),
                'recipe_generation_completed_at' => null,
            ]);

            return [
                'fingerprint' => $locked->recipe_generation_input_fingerprint,
                'input' => $locked->recipe_generation_input,
                'user_id' => $locked->recipe_generation_requested_by_user_id,
            ];
        });

        if ($claimed === null) {
            return $mealPlan->refresh();
        }

        try {
            $request = MealPlanRecipeDraftRequest::fromArray($claimed['input']);
            $draft = $this->drafter->draft($request);
            $validated = Validator::make($draft->toArray(), [
                'recipes' => ['required', 'array', 'min:1'],
                'recipes.*.planned_meal_id' => ['required', 'integer', 'min:1'],
                'recipes.*.title' => ['required', 'string', 'max:160'],
                'recipes.*.summary' => ['nullable', 'string', 'max:2000'],
                'recipes.*.servings' => ['required', 'numeric', 'min:0.25', 'max:999'],
                'recipes.*.prep_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'recipes.*.cook_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'recipes.*.ingredients' => ['required', 'array', 'min:1'],
                'recipes.*.ingredients.*.name' => ['required', 'string', 'max:255'],
                'recipes.*.ingredients.*.quantity' => ['nullable', 'numeric', 'min:0'],
                'recipes.*.ingredients.*.unit' => ['nullable', 'string', 'max:80'],
                'recipes.*.ingredients.*.preparation' => ['nullable', 'string', 'max:255'],
                'recipes.*.ingredients.*.optional' => ['required', 'boolean'],
                'recipes.*.steps' => ['required', 'array', 'min:1'],
                'recipes.*.steps.*.instruction' => ['required', 'string'],
                'recipes.*.steps.*.timer_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
                'recipes.*.equipment' => ['present', 'array'],
                'recipes.*.equipment.*' => ['required', 'string', 'max:255'],
                'recipes.*.notices' => ['present', 'array'],
                'recipes.*.notices.*.kind' => ['required', 'string', 'in:'.collect(PreparationNoticeKind::cases())->pluck('value')->join(',')],
                'recipes.*.notices.*.instruction' => ['required', 'string'],
                'recipes.*.notices.*.lead_minutes' => ['nullable', 'integer', 'min:0'],
                'recipes.*.storage_guidance' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            $expectedIds = array_map(
                static fn (array $meal): int => (int) $meal['planned_meal_id'],
                $request->meals,
            );
            $actualIds = array_map(
                static fn (array $recipe): int => (int) $recipe['planned_meal_id'],
                $validated['recipes'],
            );
            sort($expectedIds);
            sort($actualIds);

            if (count(array_unique($actualIds)) !== count($actualIds) || $actualIds !== $expectedIds) {
                throw ValidationException::withMessages([
                    'recipes' => 'The generated recipes must cover every selected meal exactly once.',
                ]);
            }

            return DB::transaction(function () use ($mealPlan, $claimed, $validated, $expectedIds): MealPlan {
                $locked = MealPlan::query()->with('team')->lockForUpdate()->findOrFail($mealPlan->id);

                if ($locked->recipe_generation_input_fingerprint !== $claimed['fingerprint']) {
                    return $locked;
                }

                $currentRequest = $this->buildRequest->handle($locked);
                if ($this->buildRequest->fingerprint($currentRequest) !== $claimed['fingerprint']) {
                    $locked->update([
                        'recipe_generation_status' => MealPlanRecipeGenerationStatus::Failed,
                        'recipe_generation_failure_code' => 'plan_changed',
                        'recipe_generation_failure_message' => 'The plan changed while Chef was preparing its recipes. Try again.',
                    ]);

                    return $locked->refresh();
                }

                $user = User::query()->findOrFail($claimed['user_id']);
                $shoppingApproval = $locked->shopping_approved_at === null ? null : [
                    'shopping_approved_by_user_id' => $locked->shopping_approved_by_user_id,
                    'shopping_approved_at' => $locked->shopping_approved_at,
                ];
                $recipesByMeal = [];
                foreach ($validated['recipes'] as $recipeData) {
                    $recipesByMeal[(int) $recipeData['planned_meal_id']] = $recipeData;
                }
                $preparedMealIds = [];

                foreach ($expectedIds as $plannedMealId) {
                    $plannedMeal = PlannedMeal::query()->lockForUpdate()->findOrFail($plannedMealId);
                    if ($plannedMeal->recipe_version_id !== null) {
                        continue;
                    }
                    if ($plannedMeal->status !== PlannedMealStatus::Planned || $plannedMeal->type !== PlannedMealType::Custom) {
                        throw ValidationException::withMessages(['meal_plan' => 'A selected meal changed before its recipe was saved.']);
                    }

                    $recipeData = $recipesByMeal[$plannedMealId];
                    $recipe = $this->createRecipe->handle(
                        $locked->team,
                        $user,
                        $recipeData['title'],
                        $recipeData['summary'] ?? null,
                        (float) $recipeData['servings'],
                        $recipeData['prep_minutes'] ?? null,
                        $recipeData['cook_minutes'] ?? null,
                        $recipeData['ingredients'],
                        $recipeData['steps'],
                        $recipeData['equipment'],
                        $recipeData['notices'],
                        idempotencyKey: hash('sha256', 'meal-plan-recipe-batch|'.$locked->team_id.'|'.$plannedMeal->id.'|'.$claimed['fingerprint']),
                        storageGuidance: $recipeData['storage_guidance'] ?? null,
                    );
                    $version = $recipe->latestVersion;
                    $plannedMeal->update([
                        'recipe_version_id' => $version->id,
                        'type' => PlannedMealType::Recipe,
                        'title' => $version->title,
                        'summary' => $version->summary,
                        'estimated_minutes' => ($version->prep_minutes ?? 0) + ($version->cook_minutes ?? 0),
                    ]);
                    $preparedMealIds[] = $plannedMeal->id;
                }

                if ($preparedMealIds !== []) {
                    $this->recordRevision->handle(
                        $locked,
                        $user,
                        'Prepared all recipes for the completed plan.',
                        ['planned_meal_ids' => $preparedMealIds],
                    );

                    if ($shoppingApproval !== null) {
                        $locked = $locked->refresh();
                        $safetyFingerprint = $this->safetyContext->fingerprint($locked);
                        $locked->update([
                            ...$shoppingApproval,
                            'shopping_approval_fingerprint' => $this->approvalContext->fingerprint($locked),
                            'safety_reviewed_context_hash' => $safetyFingerprint,
                            'confirmed_safety_context_hash' => $safetyFingerprint,
                        ]);
                    }
                }
                $locked->update([
                    'recipe_generation_status' => MealPlanRecipeGenerationStatus::Completed,
                    'recipe_generation_failure_code' => null,
                    'recipe_generation_failure_message' => null,
                    'recipe_generation_completed_at' => now(),
                ]);

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($mealPlan, $claimed, $exception): MealPlan {
                $locked = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
                if ($locked->recipe_generation_input_fingerprint !== $claimed['fingerprint']) {
                    return $locked;
                }
                $locked->update([
                    'recipe_generation_status' => MealPlanRecipeGenerationStatus::Failed,
                    'recipe_generation_failure_code' => Str::snake(class_basename($exception)),
                    'recipe_generation_failure_message' => 'Chef could not prepare the completed plan’s recipes.',
                    'recipe_generation_completed_at' => null,
                ]);

                return $locked->refresh();
            });
        }
    }
}
