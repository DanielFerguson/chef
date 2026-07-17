<?php

namespace App\Enums;

enum AutomationRunStatus: string
{
    case AwaitingBrowser = 'awaiting_browser';
    case Queued = 'queued';
    case Processing = 'processing';
    case Executing = 'executing';
    case AwaitingApproval = 'awaiting_approval';
    case Paused = 'paused';
    case Takeover = 'takeover';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Expired], true);
    }
}
