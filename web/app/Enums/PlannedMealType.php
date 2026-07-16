<?php

namespace App\Enums;

enum PlannedMealType: string
{
    case Recipe = 'recipe';
    case Custom = 'custom';
    case Leftovers = 'leftovers';
    case Takeaway = 'takeaway';
    case EatingOut = 'eating_out';
    case Open = 'open';
}
