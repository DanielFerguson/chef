<?php

namespace App\Enums;

enum AutomationRunStatus: string
{
    case CheckingConnection = 'checking_connection';
    case AwaitingReauthentication = 'awaiting_reauthentication';
    case InspectingExistingCart = 'inspecting_existing_cart';
    case AwaitingExistingCartDecision = 'awaiting_existing_cart_decision';
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingItemDecision = 'awaiting_item_decision';
    case Reconciling = 'reconciling';
    case ReadyForReview = 'ready_for_review';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::ReadyForReview,
            self::Superseded,
            self::Cancelled,
            self::Failed,
            self::Expired,
        ], true);
    }
}
