<?php

namespace App\Enums;

enum CartProductPlanItemStatus: string
{
    case Exact = 'exact';
    case Ambiguous = 'ambiguous';
    case Unresolved = 'unresolved';
}
