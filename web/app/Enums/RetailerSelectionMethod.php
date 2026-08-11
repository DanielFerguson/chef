<?php

namespace App\Enums;

enum RetailerSelectionMethod: string
{
    case SavedPreference = 'saved_preference';
    case Deterministic = 'deterministic';
    case AiRanked = 'ai_ranked';
}
