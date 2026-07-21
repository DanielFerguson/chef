<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Automation\Exceptions\ActorRecoveryReconciliationRequired;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Enums\BrowserActorStatus;
use App\Models\BrowserActor;
use App\Models\BrowserSession;
use Illuminate\Support\Str;
use Throwable;

class FakeComputerExecutor implements ComputerExecutor
{
    public bool $authenticated = true;

    public bool $botDetected = false;

    public bool $cartBotDetected = false;

    public bool $sensitiveScreen = false;

    public bool $removeLinesOnReconcile = false;

    public bool $loseNextSession = false;

    public bool $loseNextActor = false;

    public ?string $loseActorOnCommand = null;

    public int $actorStarts = 0;

    public int $actorConnections = 0;

    public ?Throwable $executeFailure = null;

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
        $actor = $this->ensureActor($session);
        $actorRecovered = false;

        if ($this->executeFailure !== null) {
            throw $this->executeFailure;
        }

        if ($this->loseNextSession) {
            $this->loseNextSession = false;

            throw new BrowserSessionLostException;
        }

        if ($this->loseNextActor || $this->loseActorOnCommand === $command->type) {
            $this->loseNextActor = false;
            $this->loseActorOnCommand = null;
            $actor->update(['status' => BrowserActorStatus::Lost, 'stopped_at' => now()]);
            $this->ensureActor($session);
            $actorRecovered = true;

            if (in_array($command->type, ['clear_cart', 'execute_action', 'prepare_item', 'prepare_and_verify_item'], true)) {
                throw new ActorRecoveryReconciliationRequired;
            }
        }

        $this->commands[] = $command;
        $session->latestActor?->update(['heartbeat_at' => now()]);

        $result = match ($command->type) {
            'ping' => new WorkerResult(true, ['control_mode' => 'agent']),
            'yield_control' => new WorkerResult(true, ['control_mode' => 'human']),
            'resume_control' => new WorkerResult(true, ['control_mode' => 'agent']),
            'shutdown' => new WorkerResult(true, ['stopped' => true]),
            'probe_authentication' => $this->probeAuthentication($session),
            'inspect_cart' => $this->inspectCart(),
            'reconcile_cart' => $this->reconcileCart(),
            'clear_cart' => $this->clearCart(),
            'prepare_and_verify_item' => $this->prepareAndVerifyItem($command),
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

        return $actorRecovered
            ? new WorkerResult(
                ok: $result->ok,
                payload: $result->payload,
                errorCode: $result->errorCode,
                errorMessage: $result->errorMessage,
                diagnostics: [...$result->diagnostics, 'actor_recovered' => true],
            )
            : $result;
    }

    public function heartbeat(BrowserSession $session): WorkerResult
    {
        return $this->execute($session, new WorkerCommand('ping'));
    }

    public function yieldControl(BrowserSession $session): void
    {
        $this->execute($session, new WorkerCommand('yield_control'));
        $session->latestActor?->update(['status' => BrowserActorStatus::HumanControl]);
    }

    public function resumeControl(BrowserSession $session): void
    {
        $this->execute($session, new WorkerCommand('resume_control'));
        $session->latestActor?->update(['status' => BrowserActorStatus::Ready]);
    }

    public function stop(BrowserSession $session): void
    {
        $actor = $session->latestActor;

        if ($actor === null || ! $actor->status->isActive()) {
            return;
        }

        $actor->update(['status' => BrowserActorStatus::Stopped, 'stopped_at' => now()]);
    }

    private function ensureActor(BrowserSession $session): BrowserActor
    {
        $actor = $session->actors()
            ->whereIn('status', collect(BrowserActorStatus::cases())->filter->isActive()->map->value->all())
            ->latest('generation')
            ->first();

        if ($actor !== null) {
            return $actor;
        }

        $generation = ((int) $session->actors()->max('generation')) + 1;
        $this->actorStarts++;
        $this->actorConnections++;

        return BrowserActor::query()->create([
            'team_id' => $session->team_id,
            'browser_session_id' => $session->id,
            'generation' => $generation,
            'actor_uuid' => (string) Str::uuid(),
            'fencing_token' => Str::random(64),
            'socket_path' => sys_get_temp_dir().'/fake-chef-actor-'.$session->id.'-'.$generation.'.sock',
            'process_id' => 10_000 + $generation,
            'status' => BrowserActorStatus::Ready,
            'started_at' => now(),
            'connected_at' => now(),
            'heartbeat_at' => now(),
            'diagnostics' => ['fake' => true],
        ]);
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

    private function prepareAndVerifyItem(WorkerCommand $command): WorkerResult
    {
        $before = $this->inspectCart()->payload;
        $preparation = $this->prepareItem($command)->payload;
        $after = $this->inspectCart()->payload;

        return new WorkerResult(true, [
            'preparation' => $preparation,
            'before' => $before,
            'after' => $after,
            'timings' => [
                'before_inspection_ms' => 1,
                'deterministic_preparation_ms' => 1,
                'after_inspection_ms' => 1,
            ],
        ], diagnostics: ['command_ms' => 3, 'fake' => true]);
    }
}
