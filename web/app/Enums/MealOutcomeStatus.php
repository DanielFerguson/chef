<?php

namespace App\Enums;

enum MealOutcomeStatus: string
{
    case Cooked = 'cooked';
    case Skipped = 'skipped';
    case Postponed = 'postponed';
    case Replaced = 'replaced';
    case Leftovers = 'leftovers';
    case AteOut = 'ate_out';
}
