<?php

namespace App\Actions\Planning;

use App\Enums\MealProposalStatus;
use App\Models\MealPlan;
use App\Models\MealProposal;
use App\Models\MealSlot;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

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

        if ($message !== null && (
            $message->team_id !== $mealPlan->team_id
            || $message->conversation?->meal_plan_id !== $mealPlan->id
        )) {
            throw new AuthorizationException('The message does not belong to this meal plan.');
        }

        $attributes = [
            'team_id' => $mealPlan->team_id,
            'meal_slot_id' => $mealSlot?->id,
            'message_id' => $message?->id,
            'title' => $title,
        ];
        $mealSlotId = $mealSlot === null ? '' : (string) $mealSlot->id;
        $idempotencyKey = $message === null ? null : hash('sha256', implode('|', [
            'meal-proposal', $mealPlan->id, $message->id, $mealSlotId, mb_strtolower(trim($title)),
        ]));

        $values = [
            'proposed_by_user_id' => $user->id,
            'summary' => $summary,
            'estimated_minutes' => $estimatedMinutes,
            'estimated_cost' => $estimatedCost,
        ];

        return DB::transaction(function () use ($mealPlan, $user, $mealSlot, $message, $attributes, $idempotencyKey, $values): MealProposal {
            $proposal = $message === null
                ? $mealPlan->proposals()->create([...$attributes, ...$values])
                : $mealPlan->proposals()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [...$attributes, ...$values],
                );

            if ($mealSlot !== null) {
                MealProposal::query()
                    ->where('meal_plan_id', $mealPlan->id)
                    ->where('meal_slot_id', $mealSlot->id)
                    ->where('status', MealProposalStatus::Pending)
                    ->whereKeyNot($proposal->id)
                    ->lockForUpdate()
                    ->get()
                    ->each(function (MealProposal $prior) use ($user): void {
                        $prior->update([
                            'status' => MealProposalStatus::Replaced,
                            'decided_by_user_id' => $user->id,
                            'decided_at' => now(),
                        ]);
                    });
            }

            return $proposal;
        });
    }
}
