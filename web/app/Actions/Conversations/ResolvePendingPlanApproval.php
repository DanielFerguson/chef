<?php

namespace App\Actions\Conversations;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Enums\MessageRole;
use App\Models\Conversation;

class ResolvePendingPlanApproval
{
    public function __construct(private readonly AssessMealPlanReadiness $assessReadiness) {}

    /** @return array{id: string, tool: string, plan_revision: int, reason: string|null}|null */
    public function handle(Conversation $conversation): ?array
    {
        $mealPlan = $conversation->mealPlan?->refresh();

        if ($mealPlan === null || ! $this->assessReadiness->handle($mealPlan)['ready_for_approval']) {
            return null;
        }

        $latestMessage = $conversation->messages()->reorder()->latest('id')->first();
        $pending = $latestMessage?->metadata['pending_tool_approval'] ?? null;

        if (
            $latestMessage?->role !== MessageRole::Assistant
            || ! is_array($pending)
            || ($pending['tool'] ?? null) !== 'ConfirmPlan'
            || ! is_string($pending['id'] ?? null)
            || ! is_numeric($pending['plan_revision'] ?? null)
            || (int) $pending['plan_revision'] !== $mealPlan->revision
        ) {
            return null;
        }

        return [
            'id' => $pending['id'],
            'tool' => 'ConfirmPlan',
            'plan_revision' => (int) $pending['plan_revision'],
            'reason' => is_string($pending['reason'] ?? null) ? $pending['reason'] : null,
        ];
    }
}
