<?php

namespace App\Enums;

enum GrocerySearchMethod: string
{
    case Deterministic = 'deterministic';
    case AiRecovery = 'ai_recovery';
    case DeterministicFallback = 'deterministic_fallback';
}
