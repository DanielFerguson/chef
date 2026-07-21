<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealProposalStatus;
use App\Models\MealPlan;

class AssessMealPlanReadiness
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    /** @return array{total_slots: int, filled_slots: int, open_slots: int, uncovered_slots: int, pending_proposals: int, slots_without_participants: int, recipes_required: int, recipes_ready: int, recipes_preparing: int, recipes_failed: int, recipes_unresolved: int, ready_for_safety_review: bool, safety_reviewed: bool, safety_review_required: bool, ready_for_safety_confirmation: bool, ready_for_confirmation: bool, confirmed: bool, next_action: string} */
    public function handle(MealPlan $mealPlan): array
    {
        $totalSlots = $mealPlan->slots()->count();
        $filledSlots = $mealPlan->slots()->whereHas('plannedMeal')->count();
        $openSlots = $totalSlots - $filledSlots;
        $pendingProposals = $mealPlan->proposals()->where('status', MealProposalStatus::Pending)->count();
        $pendingProposalSlotIds = $mealPlan->proposals()
            ->where('status', MealProposalStatus::Pending)
            ->whereNotNull('meal_slot_id')
            ->pluck('meal_slot_id');
        $uncoveredSlots = $mealPlan->slots()
            ->whereDoesntHave('plannedMeal')
            ->when($pendingProposalSlotIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $pendingProposalSlotIds))
            ->count();
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
        $recipesUnresolved = max(0, $recipeRequired - $recipesReady);
        $recipesPreparing = in_array($mealPlan->recipe_generation_status, [
            MealPlanRecipeGenerationStatus::Pending,
            MealPlanRecipeGenerationStatus::Processing,
        ], true) ? $recipesUnresolved : 0;
        $recipesFailed = $mealPlan->recipe_generation_status === MealPlanRecipeGenerationStatus::Failed
            ? $recipesUnresolved
            : 0;
        $confirmed = $mealPlan->planning_confirmed_at !== null;
        $structurallyReady = $totalSlots > 0
            && $openSlots === 0
            && $pendingProposals === 0
            && $slotsWithoutParticipants === 0;
        $ready = $structurallyReady && $recipesUnresolved === 0;
        $safetyHash = $this->safetyContext->fingerprint($mealPlan);
        $safetyReviewed = is_string($mealPlan->safety_reviewed_context_hash)
            && hash_equals($mealPlan->safety_reviewed_context_hash, $safetyHash);
        $safetyReviewRequired = ! $safetyReviewed;
        $confirmedSafetyIsCurrent = is_string($mealPlan->confirmed_safety_context_hash)
            && hash_equals($mealPlan->confirmed_safety_context_hash, $safetyHash);

        $nextAction = match (true) {
            $uncoveredSlots > 0 => 'fill_open_slots',
            $pendingProposals > 0 => 'resolve_proposals',
            $openSlots > 0 => 'fill_open_slots',
            $slotsWithoutParticipants > 0 => 'confirm_participants',
            $recipesFailed > 0 => 'retry_recipes',
            $recipesPreparing > 0 => 'wait_for_recipes',
            $recipesUnresolved > 0 => 'prepare_recipes',
            $safetyReviewRequired => 'review_safety',
            $confirmed => 'begin_shopping',
            $ready => 'review_and_confirm',
            default => 'continue_planning',
        };

        return [
            'total_slots' => $totalSlots,
            'filled_slots' => $filledSlots,
            'open_slots' => $openSlots,
            'uncovered_slots' => $uncoveredSlots,
            'pending_proposals' => $pendingProposals,
            'slots_without_participants' => $slotsWithoutParticipants,
            'recipes_required' => $recipeRequired,
            'recipes_ready' => $recipesReady,
            'recipes_preparing' => $recipesPreparing,
            'recipes_failed' => $recipesFailed,
            'recipes_unresolved' => $recipesUnresolved,
            'ready_for_safety_review' => $structurallyReady && $recipesUnresolved === 0,
            'safety_reviewed' => $safetyReviewed,
            'safety_review_required' => $safetyReviewRequired,
            'ready_for_safety_confirmation' => $structurallyReady && $confirmed && $safetyReviewed && ! $confirmedSafetyIsCurrent,
            'ready_for_confirmation' => $ready && $safetyReviewed && ! $confirmed,
            'confirmed' => $confirmed,
            'next_action' => $nextAction,
        ];
    }
}
