<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\Data\AutomationAdvanceResult;
use App\Models\AutomationRun;

class FakeComputerUseEngine implements ComputerUseEngine
{
    /** @var array<int, int> */
    public array $advancedRunIds = [];

    public AutomationAdvanceResult $result;

    public function __construct()
    {
        $this->result = new AutomationAdvanceResult(false, 'fake_checkpoint');
    }

    public function advance(AutomationRun $run): AutomationAdvanceResult
    {
        $this->advancedRunIds[] = $run->id;

        return $this->result;
    }
}
