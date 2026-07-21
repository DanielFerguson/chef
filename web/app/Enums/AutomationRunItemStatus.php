<?php

namespace App\Enums;

enum AutomationRunItemStatus: string
{
    case Pending = 'pending';
    case Searching = 'searching';
    case Matched = 'matched';
    case Substituted = 'substituted';
    case Unavailable = 'unavailable';
    case Skipped = 'skipped';
    case AwaitingDecision = 'awaiting_decision';
    case Failed = 'failed';

    public function isResolved(): bool
    {
        return in_array($this, [
            self::Matched,
            self::Substituted,
            self::Unavailable,
            self::Skipped,
        ], true);
    }
}
