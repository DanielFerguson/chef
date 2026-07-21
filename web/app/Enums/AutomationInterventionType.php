<?php

namespace App\Enums;

enum AutomationInterventionType: string
{
    case Reauthentication = 'reauthentication';
    case ExistingCart = 'existing_cart';
    case ItemDecision = 'item_decision';
    case PriceLimit = 'price_limit';
    case Substitution = 'substitution';
    case BotDetection = 'bot_detection';
    case SensitiveScreen = 'sensitive_screen';
    case CartChanged = 'cart_changed';
    case ManualTakeover = 'manual_takeover';
}
