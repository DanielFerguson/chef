<?php

namespace App\Automation\Contracts;

use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Models\BrowserSession;

interface ComputerExecutor
{
    public function execute(BrowserSession $session, WorkerCommand $command): WorkerResult;

    public function heartbeat(BrowserSession $session): WorkerResult;

    public function yieldControl(BrowserSession $session): void;

    public function resumeControl(BrowserSession $session): void;

    public function stop(BrowserSession $session): void;
}
