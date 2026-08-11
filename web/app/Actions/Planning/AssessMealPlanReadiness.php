<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Enums\MealProposalStatus;
use App\Models\MealPlan;
use App\Models\MealProposal;
use App\Models\MealSlot;
use Illuminate\Support\Collection;

class AssessMealPlanReadiness
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    /** @return array<string, mixed> */
    public function handle(MealPlan $mealPlan): array
    {
        $mealPlan->loadMissing(['slots.plannedMeal', 'slots.participants', 'proposals']);
        $pendingProposals = collect($mealPlan->proposals
            ->where('status', MealProposalStatus::Pending)
            ->values()
            ->all());
        $pendingBySlot = $pendingProposals
            ->whereNotNull('meal_slot_id')
            ->groupBy('meal_slot_id');
        $blockers = $this->blockers($mealPlan, $pendingProposals, $pendingBySlot);
        $totalSlots = $mealPlan->slots->count();
        $filledSlots = $mealPlan->slots->whereNotNull('plannedMeal')->count();
        $openSlots = $totalSlots - $filledSlots;
        $uncoveredSlots = $mealPlan->slots
            ->filter(fn (MealSlot $slot): bool => $slot->plannedMeal === null && ! $pendingBySlot->has($slot->id))
            ->count();
        $slotsWithoutParticipants = $mealPlan->slots
            ->filter(fn (MealSlot $slot): bool => $slot->participants->isEmpty())
            ->count();
        $draftReady = $totalSlots > 0 && $blockers === [];
        $confirmed = $mealPlan->planning_confirmed_at !== null;
        $hasDraftChanges = $pendingProposals->isNotEmpty() || $mealPlan->derived_data_stale_at !== null;

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

        $safetyHash = $this->safetyContext->fingerprint($mealPlan);
        $safetyReviewed = is_string($mealPlan->safety_reviewed_context_hash)
            && hash_equals($mealPlan->safety_reviewed_context_hash, $safetyHash);
        $confirmedSafetyIsCurrent = is_string($mealPlan->confirmed_safety_context_hash)
            && hash_equals($mealPlan->confirmed_safety_context_hash, $safetyHash);
        $structurallyReady = $totalSlots > 0
            && $mealPlan->slots->every(fn (MealSlot $slot): bool => $slot->plannedMeal !== null && $slot->participants->isNotEmpty())
            && $pendingProposals->isEmpty()
            && $blockers === [];
        $readyForApproval = $draftReady && (! $confirmed || $hasDraftChanges);

        $nextAction = match (true) {
            collect($blockers)->contains(fn (array $blocker): bool => in_array($blocker['code'], ['missing_meal', 'missing_meals'], true)) => 'fill_open_slots',
            collect($blockers)->contains(fn (array $blocker): bool => in_array($blocker['code'], ['conflicting_proposals', 'unassigned_proposal'], true)) => 'resolve_proposals',
            collect($blockers)->contains(fn (array $blocker): bool => in_array($blocker['code'], ['missing_participants', 'invalid_servings'], true)) => 'confirm_participants',
            $readyForApproval && $confirmed => 'review_and_approve',
            $readyForApproval && $pendingProposals->isNotEmpty() => 'resolve_proposals',
            $readyForApproval => 'review_and_approve',
            $confirmed && $recipesFailed > 0 => 'retry_recipes',
            $confirmed && $recipesPreparing > 0 => 'wait_for_recipes',
            $confirmed && $recipesUnresolved > 0 => 'prepare_recipes',
            $confirmed => 'recipes_ready',
            default => 'continue_planning',
        };

        return [
            'total_slots' => $totalSlots,
            'filled_slots' => $filledSlots,
            'open_slots' => $openSlots,
            'uncovered_slots' => $uncoveredSlots,
            'pending_proposals' => $pendingProposals->count(),
            'slots_without_participants' => $slotsWithoutParticipants,
            'recipes_required' => $recipeRequired,
            'recipes_ready' => $recipesReady,
            'recipes_preparing' => $recipesPreparing,
            'recipes_failed' => $recipesFailed,
            'recipes_unresolved' => $recipesUnresolved,
            'blockers' => $blockers,
            'ready_for_approval' => $readyForApproval,
            'ready_for_reapproval' => $readyForApproval && $confirmed,
            'ready_for_safety_review' => $draftReady,
            'safety_reviewed' => $safetyReviewed,
            'safety_review_required' => false,
            'ready_for_safety_confirmation' => $structurallyReady && $confirmed && ! $confirmedSafetyIsCurrent,
            'ready_for_confirmation' => $structurallyReady && ! $confirmed,
            'confirmed' => $confirmed,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  Collection<int, MealProposal>  $pendingProposals
     * @param  Collection<int|string, Collection<int, MealProposal>>  $pendingBySlot
     * @return list<array{code: string, meal_slot_id?: int, message: string}>
     */
    private function blockers(MealPlan $mealPlan, Collection $pendingProposals, Collection $pendingBySlot): array
    {
        $blockers = [];

        if ($mealPlan->slots->isEmpty()) {
            $blockers[] = ['code' => 'missing_meals', 'message' => 'Add at least one meal to this plan.'];
        }

        foreach ($mealPlan->slots as $slot) {
            $slotProposals = $pendingBySlot->get($slot->id, collect());

            if ($slot->plannedMeal === null && $slotProposals->isEmpty()) {
                $blockers[] = [
                    'code' => 'missing_meal',
                    'meal_slot_id' => $slot->id,
                    'message' => 'This meal slot still needs a draft meal.',
                ];
            }

            if ($slotProposals->count() > 1) {
                $blockers[] = [
                    'code' => 'conflicting_proposals',
                    'meal_slot_id' => $slot->id,
                    'message' => 'Choose one draft replacement for this meal slot.',
                ];
            }

            if ($slot->participants->isEmpty()) {
                $blockers[] = [
                    'code' => 'missing_participants',
                    'meal_slot_id' => $slot->id,
                    'message' => 'Confirm who is eating this meal.',
                ];
            } elseif ($slot->participants->contains(
                fn ($person): bool => (float) $person->getRelation('pivot')->getAttribute('servings') <= 0,
            )) {
                $blockers[] = [
                    'code' => 'invalid_servings',
                    'meal_slot_id' => $slot->id,
                    'message' => 'Every participant needs a serving amount above zero.',
                ];
            }
        }

        foreach ($pendingProposals->whereNull('meal_slot_id') as $proposal) {
            $blockers[] = [
                'code' => 'unassigned_proposal',
                'message' => 'Assign every draft meal to a plan slot.',
            ];
        }

        $approvalRevision = $mealPlan->milestones()
            ->where('kind', 'planning_confirmed')
            ->value('plan_revision');

        if ($mealPlan->derived_data_stale_at !== null
            && $approvalRevision !== null
            && $mealPlan->revision <= $approvalRevision) {
            $blockers[] = [
                'code' => 'stale_plan_state',
                'message' => 'Refresh this plan before approving it.',
            ];
        }

        return $blockers;
    }
}
