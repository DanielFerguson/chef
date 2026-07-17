<?php

namespace App\Enums;

enum BrowserConnectionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
