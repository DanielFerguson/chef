<?php

namespace App\Retailer\Browserbase;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Models\BrowserSession;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\FulfilmentOptions;
use App\Retailer\Data\SlotSelection;
use App\Retailer\Data\SubmitResult;
use App\Retailer\Data\ToolResult;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class StagehandRetailerBrowser implements RetailerBrowser
{
    public const PROTOCOL = 'chef.retailer.stagehand.v1';

    public function __construct(
        private readonly BrowserSessionProvider $sessions,
    ) {}

    public function probeAuth(BrowserSession $session): AuthCheck
    {
        $result = $this->invoke($session, 'probe_auth');
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

        return AuthCheck::fromPayload($payload);
    }

    public function inspectCart(BrowserSession $session): CartInspection
    {
        $result = $this->invoke($session, 'inspect_cart');
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

        return CartInspection::fromPayload($payload);
    }

    public function clearCart(BrowserSession $session): CartInspection
    {
        throw new RuntimeException('not implemented');
    }

    public function addProduct(BrowserSession $session, array $product): ToolResult
    {
        throw new RuntimeException('not implemented');
    }

    public function extractFulfilmentOptions(BrowserSession $session, string $fulfilmentType): FulfilmentOptions
    {
        throw new RuntimeException('not implemented');
    }

    public function applyFulfilmentSlot(BrowserSession $session, SlotSelection $slot): ToolResult
    {
        throw new RuntimeException('not implemented');
    }

    public function submitOrderWithDefaultPayment(BrowserSession $session): SubmitResult
    {
        throw new RuntimeException('not implemented');
    }

    public function extractOrderConfirmation(BrowserSession $session): SubmitResult
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invoke(BrowserSession $session, string $command, array $payload = []): array
    {
        $workerPath = (string) config(
            'services.chef_automation.stagehand_worker_path',
            base_path('automation/dist/src/main.js'),
        );

        if (! is_file($workerPath)) {
            throw new RuntimeException('The compiled Stagehand retailer worker is missing.');
        }

        $request = json_encode([
            'version' => self::PROTOCOL,
            'command' => $command,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $timeout = $command === 'probe_auth'
            ? max(1, (int) config('services.chef_automation.authentication_worker_timeout', 20))
            : max(1, (int) config('services.chef_automation.worker_timeout', 45));

        $process = new Process(
            [
                (string) config('services.chef_automation.node_binary', 'node'),
                $workerPath,
            ],
            base_path(),
        );
        $process->setEnv([
            ...$this->inheritedEnvironment(),
            'CHEF_BROWSER_CDP_URL' => $this->sessions->connectionUrl($session),
            ...(app()->environment('testing')
                ? ['CHEF_AUTOMATION_FIXTURE_MODE' => '1']
                : []),
        ]);
        $process->setTimeout((float) $timeout);
        $process->setInput($request."\n");
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw new RuntimeException(
                $stderr !== ''
                    ? 'The Stagehand retailer worker failed: '.$stderr
                    : 'The Stagehand retailer worker failed before returning a result.',
            );
        }

        try {
            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The Stagehand retailer worker returned invalid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::PROTOCOL) {
            throw new RuntimeException('The Stagehand retailer worker returned an unexpected protocol response.');
        }

        if (! ($decoded['ok'] ?? false)) {
            throw new RuntimeException(
                is_string($decoded['error_message'] ?? null)
                    ? $decoded['error_message']
                    : 'The Stagehand retailer worker rejected the command.',
            );
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function inheritedEnvironment(): array
    {
        $env = [];

        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $env[$key] = $value;
        }

        return $env;
    }
}
