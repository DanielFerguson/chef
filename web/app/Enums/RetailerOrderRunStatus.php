<?php

namespace App\Enums;

enum RetailerOrderRunStatus: string
{
    case Draft = 'draft';
    case PreparingCart = 'preparing_cart';
    case AwaitingCartDecision = 'awaiting_cart_decision';
    case AwaitingItemDecision = 'awaiting_item_decision';
    case AwaitingReauthentication = 'awaiting_reauthentication';
    case CartReady = 'cart_ready';
    case FetchingFulfilmentOptions = 'fetching_fulfilment_options';
    case AwaitingFulfilmentSelection = 'awaiting_fulfilment_selection';
    case AwaitingOrderConfirmation = 'awaiting_order_confirmation';
    case SubmittingOrder = 'submitting_order';
    case Placed = 'placed';
    case AwaitingPlacementVerification = 'awaiting_placement_verification';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function requiresHouseholdInput(): bool
    {
        return in_array($this, [
            self::AwaitingCartDecision,
            self::AwaitingItemDecision,
            self::AwaitingReauthentication,
            self::AwaitingFulfilmentSelection,
            self::AwaitingOrderConfirmation,
            self::AwaitingPlacementVerification,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Placed,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function blocksResubmit(): bool
    {
        return in_array($this, [
            self::SubmittingOrder,
            self::Placed,
            self::AwaitingPlacementVerification,
        ], true);
    }
}
