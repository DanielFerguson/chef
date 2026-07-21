<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Models\BrowserSession;

class FakeComputerExecutor implements ComputerExecutor
{
    public bool $authenticated = true;

    public bool $botDetected = false;

    public bool $cartBotDetected = false;

    public bool $sensitiveScreen = false;

    public bool $removeLinesOnReconcile = false;

    public bool $loseNextSession = false;

    public string $authenticationFailureReason = 'Woolworths requested login.';

    public int $authenticationFailuresRemaining = 0;

    public string $preparedStatus = 'matched';

    public ?string $preparedProductName = null;

    public ?float $preparedQuantity = null;

    public ?float $preparedUnitPrice = null;

    /** @var array<int, array<string, mixed>> */
    public array $cartLines = [];

    /** @var array<int, WorkerCommand> */
    public array $commands = [];

    public function execute(BrowserSession $session, WorkerCommand $command): WorkerResult
    {
        if ($this->loseNextSession) {
            $this->loseNextSession = false;

            throw new BrowserSessionLostException;
        }

        $this->commands[] = $command;

        return match ($command->type) {
            'probe_authentication' => $this->probeAuthentication($session),
            'inspect_cart' => $this->inspectCart(),
            'reconcile_cart' => $this->reconcileCart(),
            'clear_cart' => $this->clearCart(),
            'prepare_item' => $this->prepareItem($command),
            'capture' => new WorkerResult(true, [
                'screenshot' => 'data:image/png;base64,'.base64_encode('fake screenshot'),
                'url' => 'https://www.woolworths.com.au/shop/search/products?searchTerm=milk',
                'sensitive_field' => false,
                'sensitive_screen' => false,
                'bot_detected' => false,
            ]),
            'execute_action', 'navigate' => new WorkerResult(true, [
                'url' => 'https://www.woolworths.com.au/shop/search/products',
                'verified' => true,
            ]),
            default => new WorkerResult(false, errorCode: 'unsupported_command', errorMessage: 'Unsupported fake worker command.'),
        };
    }

    private function probeAuthentication(BrowserSession $session): WorkerResult
    {
        $authenticated = $this->authenticated;
        $persistedFailures = is_numeric($session->metadata['fake_authentication_failures_remaining'] ?? null)
            ? (int) $session->metadata['fake_authentication_failures_remaining']
            : 0;

        if ($persistedFailures > 0) {
            $session->update([
                'metadata' => [
                    ...($session->metadata ?? []),
                    'fake_authentication_failures_remaining' => $persistedFailures - 1,
                ],
            ]);
            $authenticated = false;
        } elseif ($this->authenticationFailuresRemaining > 0) {
            $this->authenticationFailuresRemaining--;
            $authenticated = false;
        }

        return new WorkerResult(true, [
            'authenticated' => $authenticated,
            'reason' => $authenticated ? 'Protected cart probe passed.' : $this->authenticationFailureReason,
            'bot_detected' => $this->botDetected,
            'sensitive_screen' => $this->sensitiveScreen,
        ]);
    }

    private function clearCart(): WorkerResult
    {
        $this->cartLines = [];

        return new WorkerResult(true, ['lines' => [], 'total' => 0, 'currency' => 'AUD']);
    }

    private function inspectCart(): WorkerResult
    {
        return new WorkerResult(true, [
            'lines' => $this->cartLines,
            'total' => collect($this->cartLines)->sum(fn ($line) => (float) ($line['total_price'] ?? 0)),
            'currency' => 'AUD',
            'bot_detected' => $this->botDetected || $this->cartBotDetected,
            'sensitive_screen' => $this->sensitiveScreen,
        ]);
    }

    private function reconcileCart(): WorkerResult
    {
        if ($this->removeLinesOnReconcile) {
            $this->cartLines = [];
        }

        return $this->inspectCart();
    }

    private function prepareItem(WorkerCommand $command): WorkerResult
    {
        $requirement = is_array($command->payload['requirement'] ?? null) ? $command->payload['requirement'] : [];
        $existing = collect($this->cartLines)->first(
            fn ($line) => mb_strtolower((string) ($line['product_name'] ?? '')) === mb_strtolower((string) ($requirement['name'] ?? '')),
        );
        $baselineQuantity = is_numeric($requirement['pre_existing_quantity'] ?? null)
            ? (float) $requirement['pre_existing_quantity']
            : 0;
        $packCount = is_numeric($requirement['product_match']['pack_count'] ?? null)
            ? max(1, (int) $requirement['product_match']['pack_count'])
            : 1;
        $targetQuantity = $baselineQuantity + $packCount;

        if (is_array($existing) && (float) ($existing['quantity'] ?? 0) >= $targetQuantity) {
            return new WorkerResult(true, [
                'status' => 'matched',
                'product' => $existing,
                'reason' => 'The fake cart observation found the existing line.',
            ]);
        }

        if (is_array($existing)) {
            foreach ($this->cartLines as $index => $line) {
                if (($line['external_product_id'] ?? null) !== ($existing['external_product_id'] ?? null)) {
                    continue;
                }

                $unitPrice = (float) ($line['unit_price'] ?? 3.5);
                $this->cartLines[$index]['quantity'] = $targetQuantity;
                $this->cartLines[$index]['total_price'] = $unitPrice * $targetQuantity;
                $existing = $this->cartLines[$index];
                break;
            }

            return new WorkerResult(true, [
                'status' => 'matched',
                'product' => $existing,
                'reason' => 'The fake cart observation verified an increase beyond the pre-existing quantity.',
            ]);
        }

        $quantity = $this->preparedQuantity ?? $targetQuantity;
        $unitPrice = $this->preparedUnitPrice
            ?? (is_numeric($requirement['estimated_price'] ?? null) ? (float) $requirement['estimated_price'] : 3.5);
        $line = [
            'external_product_id' => 'fake-'.count($this->cartLines),
            'product_name' => $this->preparedProductName ?? (string) ($requirement['name'] ?? 'Grocery item'),
            'quantity' => $quantity,
            'unit' => $requirement['unit'] ?? null,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ];
        $this->cartLines[] = $line;

        return new WorkerResult(true, [
            'status' => $this->preparedStatus,
            'product' => $line,
            'reason' => 'The fake cart observation verified the line.',
        ]);
    }
}
