<?php

namespace App\Enums;

enum AutomationStepStatus: string
{
    case Observed = 'observed';
    case Ready = 'ready';
    case AwaitingApproval = 'awaiting_approval';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
}
