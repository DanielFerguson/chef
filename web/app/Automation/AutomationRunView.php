<?php

namespace App\Automation;

use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;

class AutomationRunView
{
    /** @return array<string, mixed> */
    public function make(AutomationRun $run): array
    {
        $run->loadMissing([
            'items',
            'interventions' => fn ($query) => $query->where('status', AutomationInterventionStatus::Pending->value),
            'latestSnapshot.lines',
        ]);
        $resolved = $run->items->filter(fn ($item) => $item->status->isResolved())->count();

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'shopping_list_revision' => $run->frozen_snapshot['revision'] ?? null,
            'shopping_list_revision_id' => $run->shopping_list_revision_id,
            'current_shopping_list_revision' => $run->shoppingList->revision,
            'revision_diverged' => $run->shoppingList->revision !== ($run->frozen_snapshot['revision'] ?? null),
            'existing_cart_decision' => $run->existing_cart_decision?->value,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'failure_message' => $run->failure_message,
            'progress' => [
                'resolved' => $resolved,
                'total' => $run->items->count(),
                'actions_taken' => $run->actions_taken,
                'max_actions' => $run->limits['max_actions'] ?? null,
            ],
            'items' => $run->items->map(fn ($item) => [
                'id' => $item->id,
                'position' => $item->position,
                'name' => $item->requirement_snapshot['name'] ?? 'Shopping item',
                'quantity' => $item->requirement_snapshot['quantity'] ?? null,
                'unit' => $item->requirement_snapshot['unit'] ?? null,
                'status' => $item->status->value,
                'product' => $item->matched_product,
                'failure_message' => $item->failure_message,
            ])->values(),
            'intervention' => ($intervention = $run->interventions->first()) === null ? null : [
                'id' => $intervention->id,
                'type' => $intervention->type->value,
                'payload' => $intervention->payload,
                'requested_at' => $intervention->requested_at->toIso8601String(),
                'automation_run_item_id' => $intervention->automation_run_item_id,
                'takeover_url' => $intervention->type === AutomationInterventionType::ManualTakeover
                    && $intervention->browser_session_id !== null
                    ? route('browser-sessions.takeover.show', $intervention->browser_session_id)
                    : null,
            ],
            'snapshot' => $run->latestSnapshot === null ? null : [
                'id' => $run->latestSnapshot->id,
                'currency' => $run->latestSnapshot->currency,
                'chef_subtotal' => $run->latestSnapshot->chef_subtotal,
                'cart_total' => $run->latestSnapshot->cart_total,
                'captured_at' => $run->latestSnapshot->captured_at->toIso8601String(),
                'lines' => $run->latestSnapshot->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'classification' => $line->classification->value,
                    'product_name' => $line->product_name,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'total_price' => $line->total_price,
                    'pre_existing' => $line->pre_existing,
                    'metadata' => $line->metadata,
                ])->values(),
            ],
            'can_open_woolworths_cart' => $run->status === AutomationRunStatus::ReadyForReview,
            'open_woolworths_cart_url' => $run->status === AutomationRunStatus::ReadyForReview
                ? (string) config('services.woolworths.open_cart_url')
                : null,
            'normal_app_sync_proven' => (bool) config('automation.normal_app_sync_proven'),
        ];
    }
}
