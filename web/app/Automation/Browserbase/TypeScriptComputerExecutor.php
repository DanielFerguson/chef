<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Models\BrowserSession;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class TypeScriptComputerExecutor implements ComputerExecutor
{
    public function __construct(private readonly BrowserSessionProvider $sessions) {}

    public function execute(BrowserSession $session, WorkerCommand $command): WorkerResult
    {
        $process = new Process([
            (string) config('services.chef_automation.node_binary', 'node'),
            (string) config('services.chef_automation.worker_path', base_path('automation/dist/worker.js')),
        ], base_path(), [
            'CHEF_BROWSER_CDP_URL' => $this->sessions->connectionUrl($session),
        ]);
        $process->setInput(json_encode($command->toArray(), JSON_THROW_ON_ERROR)."\n");
        $workerTimeout = max(1, (int) config('services.chef_automation.worker_timeout', 45));
        $timeout = $command->type === 'probe_authentication'
            ? min($workerTimeout, max(1, (int) config('services.chef_automation.authentication_worker_timeout', 20)))
            : $workerTimeout;
        $process->setTimeout((float) $timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new WorkerResult(
                ok: false,
                errorCode: 'worker_timeout',
                errorMessage: 'Woolworths took too long to return a verifiable page.',
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The browser worker stopped before reaching a safe checkpoint.');
        }

        try {
            $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The browser worker returned an invalid response.');
        }

        if (! is_array($payload) || ($payload['version'] ?? null) !== 'chef.browser.v1') {
            throw new RuntimeException('The browser worker protocol version did not match.');
        }

        return new WorkerResult(
            ok: (bool) ($payload['ok'] ?? false),
            payload: is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            errorCode: is_string($payload['error_code'] ?? null) ? $payload['error_code'] : null,
            errorMessage: is_string($payload['error_message'] ?? null) ? $payload['error_message'] : null,
        );
    }
}
