<?php

namespace App\Actions\Recipes;

use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealProposalStatus;
use App\Jobs\MaterializeMealPlanRecipesJob;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class PrepareMealPlanRecipes
{
    public function __construct(private readonly BuildMealPlanRecipeDraftRequest $buildRequest) {}

    public function handle(MealPlan $mealPlan, User $user): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot prepare recipes for this plan.');
        }

        $totalSlots = $mealPlan->slots()->count();
        $structurallyComplete = $totalSlots > 0
            && $mealPlan->slots()->whereDoesntHave('plannedMeal')->doesntExist()
            && $mealPlan->slots()->whereDoesntHave('participants')->doesntExist()
            && $mealPlan->proposals()->where('status', MealProposalStatus::Pending)->doesntExist();

        if (! $structurallyComplete) {
            return $mealPlan->refresh();
        }

        $request = $this->buildRequest->handle($mealPlan);
        if ($request->meals === []) {
            return $mealPlan->refresh();
        }
        $input = $request->jsonSerialize();
        $fingerprint = $this->buildRequest->fingerprint($request);

        [$plan, $shouldDispatch] = DB::transaction(function () use ($mealPlan, $user, $input, $fingerprint): array {
            $locked = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->id);
            $unchangedActive = $locked->recipe_generation_input_fingerprint === $fingerprint
                && in_array($locked->recipe_generation_status, [
                    MealPlanRecipeGenerationStatus::Pending,
                    MealPlanRecipeGenerationStatus::Processing,
                ], true);

            if ($unchangedActive) {
                return [$locked, false];
            }

            $locked->update([
                'recipe_generation_requested_by_user_id' => $user->id,
                'recipe_generation_status' => MealPlanRecipeGenerationStatus::Pending,
                'recipe_generation_input_fingerprint' => $fingerprint,
                'recipe_generation_input' => $input,
                'recipe_generation_failure_code' => null,
                'recipe_generation_failure_message' => null,
                'recipe_generation_started_at' => null,
                'recipe_generation_completed_at' => null,
            ]);

            return [$locked->refresh(), true];
        });

        if ($shouldDispatch) {
            MaterializeMealPlanRecipesJob::dispatch($mealPlan->id);
        }

        return $plan;
    }
}
