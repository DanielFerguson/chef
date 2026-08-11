<?php

namespace App\Enums;

enum MealPlanAdjustmentDraftStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Superseded = 'superseded';
}
