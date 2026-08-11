<?php

namespace App\Actions\Baskets;

use App\Actions\Retailers\EnsureRetailerMutationIsEnabled;
use App\Actions\Retailers\ValidateRetailerProductCandidate;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Models\BasketRun;
use App\Models\BasketRunItem;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Exceptions\RetailerBasketUncertainException;
use App\Retailer\Exceptions\RetailerMutationAuthorityLostException;
use App\Retailer\Exceptions\RetailerMutationFailedException;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReplaceBasket
{
    public function __construct(
        private readonly RecordBasketSnapshot $recordSnapshot,
        private readonly RestoreBasket $restoreBasket,
        private readonly EnsureRetailerMutationIsEnabled $ensureMutationIsEnabled,
        private readonly ValidateRetailerProductCandidate $validateCandidate,
        private readonly RecordStagehandFallbackCount $recordFallbackCount,
    ) {}

    public function handle(BasketRun $basketRun, RetailerAutomationGateway $gateway): bool
    {
        $this->ensureMutationIsEnabled->handle();
        $basketRun->load([
            'connection',
            'items.requirement',
            'items.selection.candidate',
        ]);

        if ($basketRun->connection === null
            || $basketRun->connection->status !== RetailerConnectionStatus::Connected
            || $basketRun->connection->browserbase_context_id === null) {
            $basketRun->update(['status' => BasketRunStatus::ReauthenticationRequired]);

            return false;
        }

        $claimToken = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($basketRun, $claimToken): bool {
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);
            if ($locked->claim_token !== null && $locked->claimed_at?->isAfter(now()->subMinutes(6))) {
                return false;
            }
            if (! in_array($locked->status, [
                BasketRunStatus::RevalidatingProducts,
                BasketRunStatus::Failed,
            ], true)) {
                return false;
            }

            $locked->update([
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'status' => BasketRunStatus::RevalidatingProducts,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        $basketRun->refresh();
        $contextId = $basketRun->connection->browserbase_context_id;

        try {
            $auth = $this->execute($basketRun, $gateway, $contextId, RetailerWorkerCommand::ProbeAuth);
            if (! $auth->succeeded() || Arr::get($auth->data, 'authenticated') !== true) {
                $basketRun->update([
                    'status' => BasketRunStatus::ReauthenticationRequired,
                    'failure_code' => $auth->reasonCode ?? 'authentication_required',
                    'failure_message' => 'Reconnect Coles before Chef changes the basket.',
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);

                return false;
            }

            if (! $this->revalidateProducts($basketRun, $gateway, $contextId)) {
                return false;
            }

            $baselineInspection = $this->inspect($basketRun, $gateway, $contextId);
            $baseline = $this->recordSnapshot->handle(
                $basketRun,
                BasketSnapshotKind::Baseline,
                $baselineInspection['data'],
            );
            $basketRun->update([
                'status' => BasketRunStatus::ReplacingBasket,
                'mutation_started_at' => now(),
                'baseline_checksum' => $baselineInspection['checksum'] ?? $baseline->checksum,
            ]);
            $secondInspection = $this->inspect($basketRun, $gateway, $contextId);
            if ($this->lineQuantities($secondInspection['data']) !== $this->lineQuantities($baselineInspection['data'])) {
                throw new RetailerBasketUncertainException('The basket changed after its baseline was captured.');
            }

            $current = $this->ensureEmpty(
                $basketRun,
                $gateway,
                $contextId,
                $secondInspection,
                $this->lineQuantities($baselineInspection['data']),
            );
            $basketRun->update(['basket_cleared_at' => now()]);
            $expected = [];

            foreach ($basketRun->items->sortBy('sku') as $item) {
                $current = $this->ensureLine($basketRun, $gateway, $contextId, $current, $expected, $item);
                $expected[$item->sku] = $item->absolute_quantity;
                ksort($expected);
            }

            if ($this->lineQuantities($current['data']) !== $expected) {
                throw new RetailerBasketUncertainException('The final basket did not match the selected products.');
            }

            $final = $this->recordSnapshot->handle(
                $basketRun,
                BasketSnapshotKind::Final,
                $current['data'],
            );
            $rawActualLines = Arr::get($current['data'], 'lines');
            if (! is_array($rawActualLines)) {
                throw new RetailerBasketUncertainException('The final basket inspection was invalid.');
            }
            $actualLines = [];
            foreach ($rawActualLines as $line) {
                if (! is_array($line)) {
                    throw new RetailerBasketUncertainException('The final basket inspection contained an invalid line.');
                }
                $actualLines[(string) Arr::get($line, 'sku')] = $line;
            }

            $this->withMutationAuthority($basketRun, function (BasketRun $lockedRun) use ($final, $actualLines): void {
                foreach ($lockedRun->items()->get() as $item) {
                    $actual = $actualLines[$item->sku] ?? [];
                    $item->update([
                        'unit_price_cents' => is_numeric(Arr::get($actual, 'unit_price_cents'))
                            ? (int) Arr::get($actual, 'unit_price_cents')
                            : $item->unit_price_cents,
                        'line_price_cents' => is_numeric(Arr::get($actual, 'line_price_cents'))
                            ? (int) Arr::get($actual, 'line_price_cents')
                            : $item->line_price_cents,
                        'verification_checksum' => hash('sha256', json_encode([
                            'sku' => $item->sku,
                            'absolute_quantity' => $item->absolute_quantity,
                            'actual' => $actual,
                        ], JSON_THROW_ON_ERROR)),
                        'verified_at' => now(),
                    ]);
                }

                $lockedRun->update([
                    'status' => BasketRunStatus::Ready,
                    'final_checksum' => $final->checksum,
                    'replaced_line_count' => $lockedRun->snapshots()
                        ->where('kind', BasketSnapshotKind::Baseline)
                        ->value('line_count') ?? 0,
                    'chef_subtotal_cents' => $lockedRun->items()->sum('line_price_cents'),
                    'retailer_total_cents' => $final->retailer_total_cents,
                    'basket_captured_at' => $final->captured_at,
                    'failure_code' => null,
                    'failure_message' => null,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);
            });

            return true;
        } catch (RetailerMutationAuthorityLostException) {
            $this->recordAuthorityLoss($basketRun);

            return false;
        } catch (RetailerBasketUncertainException) {
            $basketRun->update([
                'status' => BasketRunStatus::Uncertain,
                'failure_code' => 'concurrent_basket_change',
                'failure_message' => 'The Coles basket changed unexpectedly. Chef stopped automation so you can review it.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return false;
        } catch (Throwable) {
            $basketRun->refresh();
            if (! $this->hasMutationAuthority($basketRun)) {
                $this->recordAuthorityLoss($basketRun);

                return false;
            }

            if ($basketRun->basket_cleared_at !== null) {
                try {
                    $this->restoreBasket->handle($basketRun, $gateway, continuingActiveMutation: true);
                } catch (RuntimeException) {
                    $basketRun->update([
                        'status' => BasketRunStatus::NeedsAttention,
                        'failure_code' => 'restoration_paused',
                        'failure_message' => 'Basket restoration is paused. Review the Coles basket directly.',
                        'claim_token' => null,
                        'claimed_at' => null,
                    ]);
                }

                return false;
            }

            $basketRun->update([
                'status' => BasketRunStatus::Failed,
                'failure_code' => 'basket_replacement_failed',
                'failure_message' => 'Chef stopped before it could safely replace the Coles basket.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return false;
        }
    }

    private function revalidateProducts(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        string $contextId,
    ): bool {
        $result = $this->execute(
            $basketRun,
            $gateway,
            $contextId,
            RetailerWorkerCommand::SearchProducts,
            [
                'mode' => 'revalidate',
                'requirements' => $basketRun->items->map(fn (BasketRunItem $item): array => [
                    'requirement_id' => $item->grocery_requirement_id,
                    'exact_sku' => $item->sku,
                    'queries' => [$item->sku],
                    'constraints' => $item->requirement->applicable_constraints ?? [],
                ])->values()->all(),
            ],
        );

        if (! $result->succeeded()) {
            $basketRun->update([
                'status' => in_array($result->reasonCode, ['authentication_required', 'session_expired'], true)
                    ? BasketRunStatus::ReauthenticationRequired
                    : BasketRunStatus::Failed,
                'failure_code' => $result->reasonCode ?? 'product_revalidation_failed',
                'failure_message' => 'Chef could not revalidate every selected Coles product.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return false;
        }

        $rawCandidates = Arr::get($result->data, 'candidates');
        if (! is_array($rawCandidates)) {
            $rawCandidates = [];
        }
        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            if (is_array($candidate)) {
                $candidates[(int) Arr::get($candidate, 'requirement_id')] = $candidate;
            }
        }

        foreach ($basketRun->items as $item) {
            $candidateData = $candidates[$item->grocery_requirement_id] ?? null;
            if (! is_array($candidateData)) {
                return $this->markNeedsProduct($basketRun, $item, 'selected_product_missing');
            }

            $validated = $this->validateCandidate->handle($item->requirement, $candidateData);
            $selectedCandidate = $item->selection->candidate;
            if ($validated['status'] !== RetailerCandidateStatus::Eligible
                || $validated['sku'] !== $item->sku
                || $validated['pack_unit'] !== $selectedCandidate->pack_unit
                || abs($validated['pack_quantity'] - $selectedCandidate->pack_quantity) > 0.0001) {
                return $this->markNeedsProduct($basketRun, $item, 'selected_product_changed');
            }

            $selectedCandidate->update($validated);
            $item->selection->update([
                'total_price_cents' => $item->absolute_quantity * $validated['price_cents'],
                'revalidation_checksum' => $validated['fingerprint'],
                'revalidated_at' => now(),
            ]);
            $item->update([
                'unit_price_cents' => $validated['price_cents'],
                'line_price_cents' => $item->absolute_quantity * $validated['price_cents'],
            ]);
        }

        $basketRun->update([
            'chef_subtotal_cents' => $basketRun->items()->sum('line_price_cents'),
        ]);

        return true;
    }

    private function markNeedsProduct(
        BasketRun $basketRun,
        BasketRunItem $item,
        string $reasonCode,
    ): bool {
        $item->requirement->update(['status' => GroceryRequirementStatus::NeedsProduct]);
        $basketRun->update([
            'status' => BasketRunStatus::NeedsProduct,
            'failure_code' => $reasonCode,
            'failure_message' => 'A selected Coles product changed or no longer passes every required check.',
            'claim_token' => null,
            'claimed_at' => null,
        ]);

        return false;
    }

    /**
     * @param  array{data: array<string, mixed>, checksum: string|null}  $current
     * @param  array<string, int>  $baseline
     * @return array{data: array<string, mixed>, checksum: string|null}
     */
    private function ensureEmpty(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        string $contextId,
        array $current,
        array $baseline,
    ): array {
        $result = $this->execute(
            $basketRun,
            $gateway,
            $contextId,
            RetailerWorkerCommand::EnsureBasketEmpty,
            ['expected_checksum' => $current['checksum']],
        );
        $inspection = $this->inspect($basketRun, $gateway, $contextId);
        if ($this->lineQuantities($inspection['data']) === []) {
            return $inspection;
        }

        if ($result->status === RetailerWorkerResultStatus::Retryable
            && $this->lineQuantities($inspection['data']) === $baseline) {
            $this->execute(
                $basketRun,
                $gateway,
                $contextId,
                RetailerWorkerCommand::EnsureBasketEmpty,
                ['expected_checksum' => $inspection['checksum']],
            );
            $inspection = $this->inspect($basketRun, $gateway, $contextId);
            if ($this->lineQuantities($inspection['data']) === []) {
                return $inspection;
            }
        }

        if ($this->lineQuantities($inspection['data']) !== $baseline) {
            throw new RetailerBasketUncertainException('The basket changed while Chef was clearing it.');
        }

        throw new RetailerMutationFailedException(
            $result->reasonCode ?? 'The basket could not be cleared.',
        );
    }

    /**
     * @param  array{data: array<string, mixed>, checksum: string|null}  $current
     * @param  array<string, int>  $expectedBefore
     * @return array{data: array<string, mixed>, checksum: string|null}
     */
    private function ensureLine(
        BasketRun $basketRun,
        RetailerAutomationGateway $gateway,
        string $contextId,
        array $current,
        array $expectedBefore,
        BasketRunItem $item,
    ): array {
        $result = $this->execute(
            $basketRun,
            $gateway,
            $contextId,
            RetailerWorkerCommand::EnsureBasketLine,
            [
                'product_id' => $item->sku,
                'absolute_quantity' => $item->absolute_quantity,
                'expected_checksum' => $current['checksum'],
            ],
        );
        $expectedAfter = [...$expectedBefore, $item->sku => $item->absolute_quantity];
        ksort($expectedAfter);
        $inspection = $this->inspect($basketRun, $gateway, $contextId);
        $actual = $this->lineQuantities($inspection['data']);

        if ($actual === $expectedAfter) {
            return $inspection;
        }

        if ($result->status === RetailerWorkerResultStatus::Retryable && $actual === $expectedBefore) {
            $this->execute(
                $basketRun,
                $gateway,
                $contextId,
                RetailerWorkerCommand::EnsureBasketLine,
                [
                    'product_id' => $item->sku,
                    'absolute_quantity' => $item->absolute_quantity,
                    'expected_checksum' => $inspection['checksum'],
                ],
            );
            $inspection = $this->inspect($basketRun, $gateway, $contextId);
            if ($this->lineQuantities($inspection['data']) === $expectedAfter) {
                return $inspection;
            }
        }

        if ($actual !== $expectedBefore) {
            throw new RetailerBasketUncertainException('The basket changed while Chef was adding products.');
        }

        throw new RetailerMutationFailedException(
            $result->reasonCode ?? 'A selected product could not be added.',
        );
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
        $result = in_array($command, [
            RetailerWorkerCommand::EnsureBasketEmpty,
            RetailerWorkerCommand::EnsureBasketLine,
        ], true)
            ? $this->withMutationAuthority(
                $basketRun,
                fn (): RetailerWorkerResult => $gateway->execute($contextId, $command, $payload),
            )
            : $gateway->execute($contextId, $command, $payload);
        $this->recordFallbackCount->handle($basketRun, $result);

        return $result;
    }

    /**
     * @template TResult
     *
     * @param  Closure(BasketRun): TResult  $callback
     * @return TResult
     */
    private function withMutationAuthority(BasketRun $basketRun, Closure $callback): mixed
    {
        $claimToken = $basketRun->claim_token;
        $connectionId = $basketRun->retailer_connection_id;

        return DB::transaction(function () use ($basketRun, $callback, $claimToken, $connectionId): mixed {
            $connection = $connectionId === null
                ? null
                : RetailerConnection::query()->lockForUpdate()->find($connectionId);
            $lockedRun = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);

            if ($connection === null
                || $lockedRun->retailer_connection_id !== $connection->id
                || $connection->status !== RetailerConnectionStatus::Connected
                || $connection->browserbase_context_id === null
                || $lockedRun->claim_token !== $claimToken
                || $lockedRun->status !== BasketRunStatus::ReplacingBasket
                || ! $connection->grants()
                    ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                    ->whereNull('revoked_at')
                    ->exists()) {
                throw new RetailerMutationAuthorityLostException;
            }

            return $callback($lockedRun);
        });
    }

    private function hasMutationAuthority(BasketRun $basketRun): bool
    {
        $connection = $basketRun->retailer_connection_id === null
            ? null
            : RetailerConnection::query()->find($basketRun->retailer_connection_id);

        return $connection !== null
            && $connection->status === RetailerConnectionStatus::Connected
            && $connection->browserbase_context_id !== null
            && $basketRun->claim_token !== null
            && $connection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();
    }

    private function recordAuthorityLoss(BasketRun $basketRun): void
    {
        DB::transaction(function () use ($basketRun): void {
            if ($basketRun->retailer_connection_id !== null) {
                RetailerConnection::query()->lockForUpdate()->find($basketRun->retailer_connection_id);
            }

            $lockedRun = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);
            $mutationStarted = $lockedRun->basket_cleared_at !== null;
            $lockedRun->update([
                'status' => $mutationStarted
                    ? BasketRunStatus::NeedsAttention
                    : BasketRunStatus::WaitingForConnection,
                'failure_code' => $mutationStarted
                    ? 'standing_consent_revoked_during_mutation'
                    : ($lockedRun->failure_code ?? 'standing_consent_unavailable'),
                'failure_message' => $mutationStarted
                    ? 'Standing consent was withdrawn while the basket was changing. Review the Coles basket directly.'
                    : ($lockedRun->failure_message ?? 'Reconnect consent before Chef changes the Coles basket.'),
                'claim_token' => null,
                'claimed_at' => null,
            ]);
        });
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
