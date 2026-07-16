<?php

namespace App\Enums;

enum PlannedMealStatus: string
{
    case Planned = 'planned';
    case Skipped = 'skipped';
}
