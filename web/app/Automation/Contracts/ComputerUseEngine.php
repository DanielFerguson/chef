<?php

namespace App\Automation\Contracts;

use App\Automation\Data\AutomationAdvanceResult;
use App\Models\AutomationRun;

interface ComputerUseEngine
{
    public function advance(AutomationRun $run): AutomationAdvanceResult;
}
