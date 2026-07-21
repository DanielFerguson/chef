<?php

namespace App\Enums;

enum BrowserSessionPurpose: string
{
    case Login = 'login';
    case Reauthentication = 'reauthentication';
    case CartPreparation = 'cart_preparation';
    case ManualTakeover = 'manual_takeover';
}
