<?php

namespace App\Enums;

enum AutomationReconciliationStatus: string
{
    case Matched = 'matched';
    case Substituted = 'substituted';
    case Unresolved = 'unresolved';
    case Unavailable = 'unavailable';
    case Extra = 'extra';
}
