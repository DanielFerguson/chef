<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerUseClient;
use App\Automation\Data\ComputerUseTurn;
use App\Models\AutomationRun;
use App\Models\AutomationRunItem;

class FakeComputerUseClient implements ComputerUseClient
{
    public function next(
        AutomationRun $run,
        AutomationRunItem $item,
        string $screenshotDataUrl,
        ?array $previousActionOutput = null,
    ): ComputerUseTurn {
        return new ComputerUseTurn(
            responseId: 'fake-response-'.$run->id,
            callId: null,
            action: null,
            complete: true,
            message: 'No computer-use action was needed by the fake.',
        );
    }
}
