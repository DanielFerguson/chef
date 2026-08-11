<?php

namespace App\Enums;

enum MealSlotParticipantOrigin: string
{
    case Explicit = 'explicit';
    case ProvisionalHistory = 'provisional_history';
    case FallbackHousehold = 'fallback_household';
}
