<?php

namespace App\Enums;

enum AutomationInterventionStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';
}
