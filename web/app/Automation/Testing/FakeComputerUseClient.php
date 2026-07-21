<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerUseClient;
use App\Automation\Data\ComputerUseTurn;
use App\Models\AutomationRun;
use App\Models\AutomationRunItem;

class FakeComputerUseClient implements ComputerUseClient
{
    /** @var array<int, ComputerUseTurn> */
    public array $turns = [];

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    public function next(
        AutomationRun $run,
        AutomationRunItem $item,
        string $screenshotDataUrl,
        ?array $previousActionOutput = null,
    ): ComputerUseTurn {
        $this->requests[] = [
            'run_id' => $run->id,
            'item_id' => $item->id,
            'previous_action_output' => $previousActionOutput,
        ];

        if ($this->turns !== []) {
            return array_shift($this->turns);
        }

        return new ComputerUseTurn(
            responseId: 'fake-response-'.$run->id,
            callId: null,
            actions: [],
            complete: true,
            message: 'No computer-use action was needed by the fake.',
        );
    }
}
