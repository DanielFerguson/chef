<?php

namespace App\Enums;

enum MealPlanAdjustmentKind: string
{
    case BudgetOverrun = 'budget_overrun';
    case ProductUnavailable = 'product_unavailable';
}
