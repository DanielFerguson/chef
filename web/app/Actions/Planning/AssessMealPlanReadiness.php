<?php

namespace App\Actions\Planning;

use App\Enums\MealProposalStatus;
use App\Enums\PlannedMealRecipePreparationStatus;
use App\Models\MealPlan;

class AssessMealPlanReadiness
{
    /** @return array{total_slots: int, filled_slots: int, open_slots: int, pending_proposals: int, slots_without_participants: int, recipes_required: int, recipes_ready: int, recipes_preparing: int, recipes_failed: int, recipes_unresolved: int, ready_for_confirmation: bool, confirmed: bool, next_action: string} */
    public function handle(MealPlan $mealPlan): array
    {
        $totalSlots = $mealPlan->slots()->count();
        $filledSlots = $mealPlan->slots()->whereHas('plannedMeal')->count();
        $openSlots = $totalSlots - $filledSlots;
        $pendingProposals = $mealPlan->proposals()->where('status', MealProposalStatus::Pending)->count();
        $slotsWithoutParticipants = $mealPlan->slots()->whereDoesntHave('participants')->count();
        $resolvedMealIds = $mealPlan->shoppingList?->mealResolutions()->pluck('planned_meal_id') ?? collect();
        $recipeRequired = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->whereIn('type', ['recipe', 'custom'])
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $resolvedMealIds))
            ->count();
        $recipesReady = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->whereIn('type', ['recipe', 'custom'])
            ->whereNotNull('recipe_version_id')
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $resolvedMealIds))
            ->count();
        $recipesPreparing = $mealPlan->recipePreparations()
            ->whereIn('planned_meal_recipe_preparations.status', [
                PlannedMealRecipePreparationStatus::Pending->value,
                PlannedMealRecipePreparationStatus::Processing->value,
            ])
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('planned_meal_recipe_preparations.planned_meal_id', $resolvedMealIds))
            ->count();
        $recipesFailed = $mealPlan->recipePreparations()
            ->where('planned_meal_recipe_preparations.status', PlannedMealRecipePreparationStatus::Failed->value)
            ->when($resolvedMealIds->isNotEmpty(), fn ($query) => $query->whereNotIn('planned_meal_recipe_preparations.planned_meal_id', $resolvedMealIds))
            ->count();
        $recipesUnresolved = max(0, $recipeRequired - $recipesReady);
        $confirmed = $mealPlan->planning_confirmed_at !== null;
        $ready = $totalSlots > 0
            && $openSlots === 0
            && $pendingProposals === 0
            && $slotsWithoutParticipants === 0
            && $recipesUnresolved === 0;

        $nextAction = match (true) {
            $openSlots > 0 => 'fill_open_slots',
            $pendingProposals > 0 => 'resolve_proposals',
            $slotsWithoutParticipants > 0 => 'confirm_participants',
            $recipesFailed > 0 => 'retry_recipes',
            $recipesPreparing > 0 => 'wait_for_recipes',
            $recipesUnresolved > 0 => 'prepare_recipes',
            $confirmed => 'begin_shopping',
            $ready => 'review_and_confirm',
            default => 'continue_planning',
        };

        return [
            'total_slots' => $totalSlots,
            'filled_slots' => $filledSlots,
            'open_slots' => $openSlots,
            'pending_proposals' => $pendingProposals,
            'slots_without_participants' => $slotsWithoutParticipants,
            'recipes_required' => $recipeRequired,
            'recipes_ready' => $recipesReady,
            'recipes_preparing' => $recipesPreparing,
            'recipes_failed' => $recipesFailed,
            'recipes_unresolved' => $recipesUnresolved,
            'ready_for_confirmation' => $ready && ! $confirmed,
            'confirmed' => $confirmed,
            'next_action' => $nextAction,
        ];
    }
}
