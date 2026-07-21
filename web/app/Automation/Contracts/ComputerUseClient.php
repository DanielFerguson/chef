<?php

namespace App\Automation\Contracts;

use App\Automation\Data\ComputerUseTurn;
use App\Models\AutomationRun;
use App\Models\AutomationRunItem;

interface ComputerUseClient
{
    /** @param array<string, mixed>|null $previousActionOutput */
    public function next(
        AutomationRun $run,
        AutomationRunItem $item,
        string $screenshotDataUrl,
        ?array $previousActionOutput = null,
    ): ComputerUseTurn;
}
