<?php

namespace App\Retailer;

use App\Actions\Retailer\ConfirmRetailerOrder;
use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerOrderRun;

class RetailerOrderRunView
{
    /** @return array<string, mixed> */
    public function make(RetailerOrderRun $run): array
    {
        $run->loadMissing(['items', 'shoppingList']);

        $resolved = $run->items->filter(fn ($item) => $item->status->isResolved())->count();
        $confirmPathAvailable = in_array($run->status, [
            RetailerOrderRunStatus::AwaitingOrderConfirmation,
            RetailerOrderRunStatus::SubmittingOrder,
            RetailerOrderRunStatus::Placed,
            RetailerOrderRunStatus::AwaitingPlacementVerification,
        ], true);
        $includeConfirmation = $confirmPathAvailable
            || $run->selected_slot !== null
            || $run->confirmation !== null;

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'shopping_list_revision_id' => $run->shopping_list_revision_id,
            'current_shopping_list_revision' => $run->shoppingList->revision,
            'existing_cart_decision' => $run->existing_cart_decision?->value,
            'fulfilment_type' => $run->fulfilment_type,
            'fulfilment_options' => $run->fulfilment_options,
            'fulfilment_options_expires_at' => $run->fulfilment_options_expires_at?->toIso8601String(),
            'selected_slot' => $run->selected_slot,
            'confirmation' => $includeConfirmation
                ? ConfirmRetailerOrder::confirmationPayload($run)
                : null,
            'cart_checksum' => $run->cart_checksum,
            'retailer_order_reference' => $run->retailer_order_reference,
            'failure_message' => $run->failure_message,
            'expires_at' => $run->expires_at?->toIso8601String(),
            'cart_decision_needed' => $run->status === RetailerOrderRunStatus::AwaitingCartDecision,
            'placement_verification_needed' => $run->status === RetailerOrderRunStatus::AwaitingPlacementVerification,
            'progress' => [
                'resolved' => $resolved,
                'total' => $run->items->count(),
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
            // Never promote “checkout in Woolworths yourself” while Chef can confirm/submit.
            'can_open_woolworths_cart' => false,
            'open_woolworths_cart_url' => $run->status === RetailerOrderRunStatus::AwaitingPlacementVerification
                ? (string) config('services.woolworths.open_cart_url')
                : null,
        ];
    }
}
