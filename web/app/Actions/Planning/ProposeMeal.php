<?php

namespace App\Actions\Planning;

use App\Models\MealPlan;
use App\Models\MealProposal;
use App\Models\MealSlot;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ProposeMeal
{
    public function handle(
        MealPlan $mealPlan,
        User $user,
        string $title,
        ?MealSlot $mealSlot = null,
        ?string $summary = null,
        ?int $estimatedMinutes = null,
        ?float $estimatedCost = null,
        ?Message $message = null,
    ): MealProposal {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        if ($mealSlot !== null && ($mealSlot->team_id !== $mealPlan->team_id || $mealSlot->meal_plan_id !== $mealPlan->id)) {
            throw new AuthorizationException('The meal slot does not belong to this plan.');
        }

        if ($message !== null && $message->team_id !== $mealPlan->team_id) {
            throw new AuthorizationException('The message does not belong to this family.');
        }

        return $mealPlan->proposals()->create([
            'team_id' => $mealPlan->team_id,
            'meal_slot_id' => $mealSlot?->id,
            'message_id' => $message?->id,
            'proposed_by_user_id' => $user->id,
            'title' => $title,
            'summary' => $summary,
            'estimated_minutes' => $estimatedMinutes,
            'estimated_cost' => $estimatedCost,
        ]);
    }
}
