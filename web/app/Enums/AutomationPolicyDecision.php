<?php

namespace App\Enums;

enum AutomationPolicyDecision: string
{
    case Allowed = 'allowed';
    case Blocked = 'blocked';
    case RequiresIntervention = 'requires_intervention';
}
