<?php

namespace App\Retailer\Browserbase;

use App\Enums\RetailerWorkerCommand;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerLiveSession;
use App\Retailer\Data\RetailerWorkerResult;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use UnexpectedValueException;

class TypeScriptRetailerAutomationGateway implements RetailerAutomationGateway
{
    public function createContext(): string
    {
        $result = $this->invoke(['operation' => 'context.create']);
        $contextId = $result['context_id'] ?? null;

        if (! is_string($contextId) || trim($contextId) === '') {
            throw new UnexpectedValueException('The retailer worker did not create a Context.');
        }

        return $contextId;
    }

    public function deleteContext(string $contextId): void
    {
        $this->invoke([
            'operation' => 'context.delete',
            'context_id' => $contextId,
        ]);
    }

    public function startLiveSession(string $contextId, string $purpose): RetailerLiveSession
    {
        return RetailerLiveSession::fromArray($this->invoke([
            'operation' => 'session.start',
            'context_id' => $contextId,
            'purpose' => $purpose,
        ]));
    }

    public function relayLiveInput(
        string $contextId,
        string $sessionId,
        #[\SensitiveParameter] ?string $text,
        ?string $key,
    ): RetailerWorkerResult {
        return RetailerWorkerResult::fromArray($this->invoke([
            'operation' => 'session.input',
            'context_id' => $contextId,
            'session_id' => $sessionId,
            'input' => $text !== null
                ? ['kind' => 'text', 'value' => $text]
                : ['kind' => 'key', 'value' => $key],
        ]));
    }

    public function execute(
        string $contextId,
        RetailerWorkerCommand $command,
        array $payload = [],
        ?string $sessionId = null,
    ): RetailerWorkerResult {
        return RetailerWorkerResult::fromArray($this->invoke([
            'operation' => 'command.execute',
            'context_id' => $contextId,
            'session_id' => $sessionId,
            'command' => $command->value,
            'payload' => $payload,
        ]));
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function invoke(#[\SensitiveParameter] array $request): array
    {
        $entrypoint = (string) config('retailer.worker.entrypoint');
        if (! is_file($entrypoint)) {
            throw new RuntimeException('The retailer automation worker has not been built.');
        }

        $process = new Process(
            [(string) config('retailer.worker.node_binary', 'node'), $entrypoint],
            base_path(),
            [
                'BROWSERBASE_API_KEY' => (string) config('retailer.browserbase.api_key'),
                'BROWSERBASE_PROJECT_ID' => (string) config('retailer.browserbase.project_id'),
                'BROWSERBASE_REGION' => (string) config('retailer.browserbase.region'),
                'BROWSERBASE_PROXY_COUNTRY' => (string) config('retailer.browserbase.proxy_country'),
                'BROWSERBASE_SESSION_TIMEOUT_SECONDS' => (string) config('retailer.browserbase.session_timeout_seconds'),
            ],
            json_encode([
                'protocol' => config('retailer.protocol'),
                ...$request,
            ], JSON_THROW_ON_ERROR),
            (float) config('retailer.worker.timeout_seconds', 120),
        );

        try {
            $process->mustRun();
        } catch (Throwable $exception) {
            throw new RuntimeException('The retailer automation worker was unavailable.', previous: $exception);
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            throw new UnexpectedValueException('The retailer automation worker returned invalid JSON.');
        }

        return $decoded;
    }
}
