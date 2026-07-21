<?php

namespace App\Enums;

enum CartLineClassification: string
{
    case Matched = 'matched';
    case Substituted = 'substituted';
    case Unavailable = 'unavailable';
    case QuantityAdjusted = 'quantity_adjusted';
    case PriceChanged = 'price_changed';
    case PreExisting = 'pre_existing';
    case Unresolved = 'unresolved';
}
