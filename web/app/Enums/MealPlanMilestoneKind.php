<?php

namespace App\Enums;

enum MealPlanMilestoneKind: string
{
    case PlanningConfirmed = 'planning_confirmed';
    case CookingStarted = 'cooking_started';
    case ReviewCompleted = 'review_completed';
}
