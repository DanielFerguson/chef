<?php

namespace App\Enums;

enum MealPlanMilestoneKind: string
{
    case PlanningConfirmed = 'planning_confirmed';
    case ShoppingListGenerated = 'shopping_list_generated';
    case ShoppingCompleted = 'shopping_completed';
    case CookingStarted = 'cooking_started';
    case ReviewCompleted = 'review_completed';
}
