<?php

namespace App\Enums;

enum BrowserActorStatus: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case HumanControl = 'human_control';
    case Recovering = 'recovering';
    case Fenced = 'fenced';
    case Stopped = 'stopped';
    case Lost = 'lost';

    public function isActive(): bool
    {
        return in_array($this, [
            self::Starting,
            self::Ready,
            self::HumanControl,
            self::Recovering,
        ], true);
    }
}
