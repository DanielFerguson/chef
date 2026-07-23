<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealProposalStatus;
use App\Models\MealPlan;

class AssessMealPlanReadiness
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    /** @return array{total_slots: int, filled_slots: int, open_slots: int, uncovered_slots: int, pending_proposals: int, slots_without_participants: int, recipes_required: int, recipes_ready: int, recipes_preparing: int, recipes_failed: int, recipes_unresolved: int, ready_for_approval: bool, ready_for_safety_review: bool, safety_reviewed: bool, safety_review_required: bool, ready_for_safety_confirmation: bool, ready_for_confirmation: bool, confirmed: bool, next_action: string} */
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
        $proposalCoveredSlots = $mealPlan->proposals()
            ->where('status', MealProposalStatus::Pending)
            ->whereNotNull('meal_slot_id')
            ->distinct()
            ->count('meal_slot_id');
        $recipeRequired = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->whereIn('type', ['recipe', 'custom'])
            ->count();
        $recipesReady = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->whereIn('type', ['recipe', 'custom'])
            ->whereNotNull('recipe_version_id')
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
        $draftReady = $totalSlots > 0
            && $uncoveredSlots === 0
            && $slotsWithoutParticipants === 0
            && ($filledSlots + $proposalCoveredSlots) === $totalSlots;
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
            $confirmed => 'begin_shopping',
            $draftReady => 'review_and_approve',
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
            'ready_for_approval' => $draftReady && ! $confirmed,
            'ready_for_safety_review' => $draftReady,
            'safety_reviewed' => $safetyReviewed,
            'safety_review_required' => $safetyReviewRequired,
            'ready_for_safety_confirmation' => $structurallyReady && $confirmed && $safetyReviewed && ! $confirmedSafetyIsCurrent,
            'ready_for_confirmation' => $structurallyReady && $safetyReviewed && ! $confirmed,
            'confirmed' => $confirmed,
            'next_action' => $nextAction,
        ];
    }
}
