<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelectPlannedMeal
{
    public function __construct(
        private readonly RecordMealPlanRevision $recordRevision,
        private readonly BuildRecommendationExplanation $buildExplanation,
    ) {}

    public function handle(MealSlot $slot, User $user, PlannedMealType $type, ?RecipeVersion $recipeVersion = null, ?string $title = null, ?string $summary = null, ?float $servings = null, ?int $estimatedMinutes = null, ?float $estimatedCost = null, ?PlannedMeal $sourcePlannedMeal = null, ?int $expectedRevision = null): PlannedMeal
    {
        if (! $user->memberships()->where('team_id', $slot->team_id)->exists()) {
            throw new AuthorizationException('You cannot select a meal for this plan.');
        }

        if ($type === PlannedMealType::Recipe && ($recipeVersion === null || $recipeVersion->team_id !== $slot->team_id)) {
            throw ValidationException::withMessages(['recipe_version_id' => 'Choose a recipe from this family.']);
        }

        if ($type === PlannedMealType::Leftovers && ($sourcePlannedMeal === null || $sourcePlannedMeal->team_id !== $slot->team_id)) {
            throw ValidationException::withMessages(['source_planned_meal_id' => 'Choose the meal these leftovers come from.']);
        }

        return DB::transaction(function () use ($slot, $user, $type, $recipeVersion, $title, $summary, $servings, $estimatedMinutes, $estimatedCost, $sourcePlannedMeal, $expectedRevision): PlannedMeal {
            $plan = $slot->mealPlan;
            $resolvedTitle = match ($type) {
                PlannedMealType::Recipe => $recipeVersion->title,
                PlannedMealType::Leftovers => 'Leftovers: '.$sourcePlannedMeal->title,
                PlannedMealType::Takeaway => trim($title ?: 'Takeaway'),
                PlannedMealType::EatingOut => trim($title ?: 'Eating out'),
                PlannedMealType::Open => trim($title ?: 'Open'),
                default => trim($title ?? ''),
            };

            if ($resolvedTitle === '') {
                throw ValidationException::withMessages(['title' => 'Name this meal.']);
            }

            $plannedMeal = PlannedMeal::query()->updateOrCreate(
                ['meal_slot_id' => $slot->id],
                [
                    'team_id' => $slot->team_id,
                    'meal_plan_id' => $slot->meal_plan_id,
                    'recipe_version_id' => $recipeVersion?->id,
                    'source_planned_meal_id' => $sourcePlannedMeal?->id,
                    'selected_by_user_id' => $user->id,
                    'type' => $type,
                    'status' => PlannedMealStatus::Planned,
                    'servings' => $servings ?? max(1, (float) $slot->participants()->sum('meal_slot_participants.servings')),
                    'title' => $resolvedTitle,
                    'summary' => $summary ?? $recipeVersion?->summary,
                    'estimated_minutes' => $estimatedMinutes ?? ($recipeVersion === null ? null : ($recipeVersion->prep_minutes ?? 0) + ($recipeVersion->cook_minutes ?? 0)),
                    'estimated_cost' => $estimatedCost,
                    'recommendation_explanation' => $recipeVersion === null ? null : $this->buildExplanation->handle($plan, $recipeVersion, $estimatedCost),
                ],
            );

            $this->recordRevision->handle($plan, $user, 'Selected '.$resolvedTitle.' for '.$slot->date->toDateString().'.', [
                'meal_slot_id' => $slot->id,
                'planned_meal_id' => $plannedMeal->id,
                'type' => $type->value,
                'recipe_version_id' => $recipeVersion?->id,
            ], $expectedRevision);

            return $plannedMeal->load('recipeVersion');
        });
    }
}
