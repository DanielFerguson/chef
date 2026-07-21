<?php

namespace App\Enums;

enum ShoppingListGenerationMethod: string
{
    case OneShot = 'one_shot';
    case DeterministicFallback = 'deterministic_fallback';
}
