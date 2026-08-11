<?php

namespace App\Enums;

enum GroceryPlanStatus: string
{
    case Building = 'building';
    case Ready = 'ready';
    case NeedsProduct = 'needs_product';
    case Superseded = 'superseded';
    case Failed = 'failed';
}
