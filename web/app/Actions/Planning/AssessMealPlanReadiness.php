<?php

namespace App\Actions\Planning;

use App\Enums\MealProposalStatus;
use App\Models\MealPlan;

class AssessMealPlanReadiness
{
    /** @return array{total_slots: int, filled_slots: int, open_slots: int, pending_proposals: int, slots_without_participants: int, ready_for_confirmation: bool, confirmed: bool, next_action: string} */
    public function handle(MealPlan $mealPlan): array
    {
        $totalSlots = $mealPlan->slots()->count();
        $filledSlots = $mealPlan->slots()->whereHas('plannedMeal')->count();
        $openSlots = $totalSlots - $filledSlots;
        $pendingProposals = $mealPlan->proposals()->where('status', MealProposalStatus::Pending)->count();
        $slotsWithoutParticipants = $mealPlan->slots()->whereDoesntHave('participants')->count();
        $confirmed = $mealPlan->planning_confirmed_at !== null;
        $ready = $totalSlots > 0
            && $openSlots === 0
            && $pendingProposals === 0
            && $slotsWithoutParticipants === 0;

        $nextAction = match (true) {
            $confirmed => 'begin_shopping',
            $openSlots > 0 => 'fill_open_slots',
            $pendingProposals > 0 => 'resolve_proposals',
            $slotsWithoutParticipants > 0 => 'confirm_participants',
            $ready => 'review_and_confirm',
            default => 'continue_planning',
        };

        return [
            'total_slots' => $totalSlots,
            'filled_slots' => $filledSlots,
            'open_slots' => $openSlots,
            'pending_proposals' => $pendingProposals,
            'slots_without_participants' => $slotsWithoutParticipants,
            'ready_for_confirmation' => $ready && ! $confirmed,
            'confirmed' => $confirmed,
            'next_action' => $nextAction,
        ];
    }
}
