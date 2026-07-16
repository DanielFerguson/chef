<?php

namespace App\Enums;

enum MealSlotKind: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';
    case Custom = 'custom';
}
