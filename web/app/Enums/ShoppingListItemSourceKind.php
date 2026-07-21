<?php

namespace App\Enums;

enum ShoppingListItemSourceKind: string
{
    case Recipe = 'recipe';
    case PlanGenerated = 'plan_generated';
    case PlannedMeal = 'planned_meal';
    case Manual = 'manual';
    case Staple = 'staple';
}
