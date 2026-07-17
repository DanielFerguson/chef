<?php

namespace App\Automation\Contracts;

use App\Automation\Data\ComputerUseStep;
use App\Models\AutomationRun;

interface ComputerUseEngine
{
    public function advance(AutomationRun $run): ComputerUseStep;
}
