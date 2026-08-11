<?php

namespace App\Actions\Baskets;

use App\Actions\Retailers\EnsureRetailerMutationIsEnabled;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Models\BasketRun;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Exceptions\RetailerMutationFailedException;
use Illuminate\Support\Arr;
use Throwable;

class RestoreBasket
{
    public function __construct(
        private readonly RecordBasketSnapshot $recordSnapshot,
        private readonly EnsureRetailerMutationIsEnabled $ensureMutationIsEnabled,
        private readonly RecordStagehandFallbackCount $recordFallbackCount,
    ) {}

    public function handle(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        bool $continuingActiveMutation = false,
    ): bool {
        $this->ensureMutationIsEnabled->handle($continuingActiveMutation);
        $basketRun->load([
            'connection',
            'snapshots' => fn ($query) => $query->where('kind', BasketSnapshotKind::Baseline),
            'snapshots.lines',
        ]);
        $baseline = $basketRun->snapshots->first();

        if ($baseline === null
            || $basketRun->connection === null
            || $basketRun->connection->status !== RetailerConnectionStatus::Connected
            || $basketRun->connection->browserbase_context_id === null) {
            $basketRun->update([
                'status' => BasketRunStatus::NeedsAttention,
                'failure_code' => 'restoration_unavailable',
                'failure_message' => 'Chef could not safely restore the previous basket.',
            ]);

            return false;
        }

        $basketRun->update([
            'status' => BasketRunStatus::Restoring,
            'restore_requested_at' => $basketRun->restore_requested_at ?? now(),
        ]);
        $contextId = $basketRun->connection->browserbase_context_id;

        try {
            $current = $this->inspect($basketRun, $gateway, $contextId);
            $empty = $this->execute(
                $basketRun,
                $gateway,
                $contextId,
                RetailerWorkerCommand::EnsureBasketEmpty,
                ['expected_checksum' => $current['checksum']],
            );

            if (! $empty->succeeded()) {
                $inspection = $this->inspect($basketRun, $gateway, $contextId);
                if ($this->lineQuantities($inspection['data']) !== []) {
                    throw new RetailerMutationFailedException('The basket could not be cleared for restoration.');
                }
                $current = $inspection;
            } else {
                $current = $this->inspect($basketRun, $gateway, $contextId);
            }

            if ($this->lineQuantities($current['data']) !== []) {
                throw new RetailerMutationFailedException('The basket was not empty after the clear command.');
            }

            $expected = [];
            foreach ($baseline->lines->sortBy('sku') as $line) {
                $result = $this->execute(
                    $basketRun,
                    $gateway,
                    $contextId,
                    RetailerWorkerCommand::EnsureBasketLine,
                    [
                        'product_id' => $line->sku,
                        'absolute_quantity' => $line->absolute_quantity,
                        'expected_checksum' => $current['checksum'],
                    ],
                );
                $expected[$line->sku] = $line->absolute_quantity;
                $current = $this->inspect($basketRun, $gateway, $contextId);

                if ($this->lineQuantities($current['data']) !== $expected) {
                    throw new RetailerMutationFailedException(
                        $result->reasonCode ?? 'A previous basket line could not be restored.',
                    );
                }
            }

            $snapshot = $this->recordSnapshot->handle(
                $basketRun,
                BasketSnapshotKind::Restoration,
                $current['data'],
            );
            $basketRun->update([
                'status' => BasketRunStatus::Restored,
                'final_checksum' => $snapshot->checksum,
                'retailer_total_cents' => $snapshot->retailer_total_cents,
                'basket_captured_at' => $snapshot->captured_at,
                'restore_completed_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return true;
        } catch (Throwable) {
            $basketRun->update([
                'status' => BasketRunStatus::NeedsAttention,
                'failure_code' => 'restoration_incomplete',
                'failure_message' => 'The previous Coles basket may not be fully restored. Review it before checkout.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return false;
        }
    }

    /** @return array{data: array<string, mixed>, checksum: string|null} */
    private function inspect(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        string $contextId,
    ): array {
        $inspection = $this->execute(
            $basketRun,
            $gateway,
            $contextId,
            RetailerWorkerCommand::InspectBasket,
        );
        if (! $inspection->succeeded()) {
            throw new RetailerMutationFailedException(
                $inspection->reasonCode ?? 'The basket could not be inspected.',
            );
        }

        return [
            'data' => $inspection->data,
            'checksum' => $inspection->verificationChecksum,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function execute(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        string $contextId,
        RetailerWorkerCommand $command,
        array $payload = [],
    ): RetailerWorkerResult {
        $result = $gateway->execute($contextId, $command, $payload);
        $this->recordFallbackCount->handle($basketRun, $result);

        return $result;
    }

    /** @param array<string, mixed> $inspection
     * @return array<string, int>
     */
    private function lineQuantities(array $inspection): array
    {
        $lines = Arr::get($inspection, 'lines');
        if (! is_array($lines)) {
            throw new RetailerMutationFailedException('The basket inspection did not contain valid lines.');
        }

        $quantities = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw new RetailerMutationFailedException('The basket inspection contained an invalid line.');
            }

            $sku = trim((string) Arr::get($line, 'sku'));
            $quantity = filter_var(
                Arr::get($line, 'absolute_quantity'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if ($sku === '' || $quantity === false || array_key_exists($sku, $quantities)) {
                throw new RetailerMutationFailedException('The basket inspection contained an incomplete or duplicate line.');
            }

            $quantities[$sku] = $quantity;
        }

        ksort($quantities);

        return $quantities;
    }
}
