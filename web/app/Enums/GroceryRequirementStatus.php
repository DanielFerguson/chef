<?php

namespace App\Enums;

enum GroceryRequirementStatus: string
{
    case Pending = 'pending';
    case Discovering = 'discovering';
    case Selected = 'selected';
    case NeedsProduct = 'needs_product';
}
