<?php

namespace App\Enums;

enum BrowserSessionStatus: string
{
    case Creating = 'creating';
    case HumanControl = 'human_control';
    case AgentControl = 'agent_control';
    case Closing = 'closing';
    case Closed = 'closed';
    case Failed = 'failed';
    case Expired = 'expired';
}
