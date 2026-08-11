<?php

namespace App\Retailer\Testing;

use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerLiveSession;
use App\Retailer\Data\RetailerWorkerResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class FakeRetailerAutomationGateway implements RetailerAutomationGateway
{
    /**
     * @var array<string, list<array{
     *     result: RetailerWorkerResult,
     *     basket_lines_after: list<array<string, mixed>>|null
     * }>>
     */
    private array $queuedResults = [];

    /** @var list<array<string, mixed>> */
    public array $commands = [];

    /** @var list<string> */
    public array $deletedContexts = [];

    /** @var list<array<string, mixed>> */
    public array $basketLines = [];

    /** @var list<array<string, mixed>> */
    public array $searchCandidates = [];

    /** @var list<array{context_id: string, session_id: string, kind: string, value: string}> */
    public array $relayedInputs = [];

    public bool $authenticated = true;

    public ?string $liveViewUrl = null;

    /** @var array<string, array<string, mixed>> */
    private array $catalog = [];

    public function __construct()
    {
        $fixturePath = config('retailer.testing.recorded_fixture');
        if (! is_string($fixturePath) || $fixturePath === '') {
            return;
        }

        $resolvedPath = str_starts_with($fixturePath, DIRECTORY_SEPARATOR)
            ? $fixturePath
            : base_path($fixturePath);
        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read the recorded retailer fixture [{$resolvedPath}].");
        }

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($fixture)) {
            throw new RuntimeException("The recorded retailer fixture [{$resolvedPath}] must contain an object.");
        }

        $this->searchCandidates = array_values(array_filter(
            $fixture['search_candidates'] ?? [],
            is_array(...),
        ));
        $this->basketLines = array_values(array_filter(
            $fixture['basket_lines'] ?? [],
            is_array(...),
        ));
        $this->authenticated = ($fixture['authenticated'] ?? true) === true;
        $this->liveViewUrl = is_string($fixture['live_view_url'] ?? null)
            ? $fixture['live_view_url']
            : null;
    }

    public function createContext(): string
    {
        return 'context_'.Str::lower(Str::random(12));
    }

    public function deleteContext(string $contextId): void
    {
        $this->deletedContexts[] = $contextId;
    }

    public function startLiveSession(string $contextId, string $purpose): RetailerLiveSession
    {
        $sessionId = 'session_'.Str::lower(Str::random(12));

        return new RetailerLiveSession(
            sessionId: $sessionId,
            liveViewUrl: $this->liveViewUrl ?? "https://live.example.test/{$sessionId}",
            expiresAt: CarbonImmutable::now()->addMinutes(5),
        );
    }

    public function relayLiveInput(
        string $contextId,
        string $sessionId,
        #[\SensitiveParameter] ?string $text,
        ?string $key,
    ): RetailerWorkerResult {
        $kind = $text !== null ? 'text' : 'key';
        $value = $text ?? (string) $key;
        $this->relayedInputs[] = [
            'context_id' => $contextId,
            'session_id' => $sessionId,
            'kind' => $kind,
            'value' => $value,
        ];
        $data = ['forwarded' => true, 'kind' => $kind];

        return new RetailerWorkerResult(
            status: RetailerWorkerResultStatus::Succeeded,
            reasonCode: null,
            verificationChecksum: $this->checksum($data),
            data: $data,
        );
    }

    public function execute(
        string $contextId,
        RetailerWorkerCommand $command,
        array $payload = [],
        ?string $sessionId = null,
    ): RetailerWorkerResult {
        $this->commands[] = compact('contextId', 'command', 'payload', 'sessionId');
        $queue = $this->queuedResults[$command->value] ?? [];
        $queued = array_shift($queue);
        $this->queuedResults[$command->value] = $queue;

        if (is_array($queued)) {
            if ($queued['basket_lines_after'] !== null) {
                $this->rememberLines($queued['basket_lines_after']);
                $this->basketLines = $queued['basket_lines_after'];
            }

            return $queued['result'];
        }

        $data = match ($command) {
            RetailerWorkerCommand::ProbeAuth => ['authenticated' => $this->authenticated],
            RetailerWorkerCommand::SearchProducts => $this->searchProducts($payload),
            RetailerWorkerCommand::InspectBasket => $this->basketInspection(),
            RetailerWorkerCommand::EnsureBasketEmpty => $this->emptyBasket(),
            RetailerWorkerCommand::EnsureBasketLine => $this->ensureBasketLine($payload),
            RetailerWorkerCommand::ReleaseSession => ['released' => true],
        };

        return new RetailerWorkerResult(
            status: $command === RetailerWorkerCommand::ProbeAuth && ! $this->authenticated
                ? RetailerWorkerResultStatus::Blocked
                : RetailerWorkerResultStatus::Succeeded,
            reasonCode: $command === RetailerWorkerCommand::ProbeAuth && ! $this->authenticated
                ? 'authentication_required'
                : null,
            verificationChecksum: $this->checksum($data),
            data: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{candidates: list<array<string, mixed>>, attempts: list<array<string, mixed>>}
     */
    private function searchProducts(array $payload): array
    {
        $requirements = $payload['requirements'] ?? [];
        if (! is_array($requirements)) {
            $requirements = [];
        }

        $requirementIds = [];
        $exactSkus = [];
        foreach ($requirements as $requirement) {
            if (! is_array($requirement) || ! is_numeric($requirement['requirement_id'] ?? null)) {
                continue;
            }

            $requirementId = (int) $requirement['requirement_id'];
            $requirementIds[$requirementId] = $requirementId;

            if (is_string($requirement['exact_sku'] ?? null)) {
                $exactSkus[$requirementId] = $requirement['exact_sku'];
            }
        }

        $candidates = [];
        foreach ($this->searchCandidates as $candidate) {
            if (! is_numeric($candidate['requirement_id'] ?? null) && count($requirementIds) === 1) {
                $candidate['requirement_id'] = reset($requirementIds);
            }

            $requirementId = (int) ($candidate['requirement_id'] ?? 0);
            $exactSku = $exactSkus[$requirementId] ?? null;
            if ($exactSku !== null && (string) ($candidate['sku'] ?? '') !== $exactSku) {
                continue;
            }

            $candidates[] = $candidate;
        }

        return [
            'candidates' => $candidates,
            'attempts' => $this->searchAttempts($payload, $candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function searchAttempts(array $payload, array $candidates): array
    {
        $requirements = $payload['requirements'] ?? [];
        if (! is_array($requirements)) {
            return [];
        }

        $attempts = [];
        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }
            $requirementId = (int) ($requirement['requirement_id'] ?? 0);
            $resultCount = 0;
            foreach ($candidates as $candidate) {
                if ((int) ($candidate['requirement_id'] ?? 0) === $requirementId) {
                    $resultCount++;
                }
            }
            $queries = $requirement['queries'] ?? [];
            if (! is_array($queries)) {
                continue;
            }
            foreach ($queries as $query) {
                if (! is_string($query)) {
                    continue;
                }
                $attempts[] = [
                    'requirement_id' => $requirementId,
                    'query' => $query,
                    'result_count' => $resultCount,
                    'eligible_result_count' => $resultCount,
                    'reason_code' => $resultCount === 0 ? 'no_results' : null,
                ];
            }
        }

        return $attempts;
    }

    /**
     * @param  list<array<string, mixed>>|null  $basketLinesAfter
     */
    public function queueResult(
        RetailerWorkerCommand $command,
        RetailerWorkerResult $result,
        ?array $basketLinesAfter = null,
    ): void {
        $this->queuedResults[$command->value][] = [
            'result' => $result,
            'basket_lines_after' => $basketLinesAfter,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyBasket(): array
    {
        $this->rememberLines($this->basketLines);
        $this->basketLines = [];

        return [
            'observed_line_count' => 0,
            'basket_checksum' => $this->checksum($this->basketInspection()),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function ensureBasketLine(array $payload): array
    {
        $sku = (string) ($payload['product_id'] ?? '');
        $quantity = (int) ($payload['absolute_quantity'] ?? 0);
        $this->rememberLines($this->basketLines);
        $product = collect($this->searchCandidates)
            ->first(fn (array $candidate): bool => (string) ($candidate['sku'] ?? '') === $sku);
        $product ??= $this->catalog[$sku] ?? [];
        $unitPrice = is_numeric($product['price_cents'] ?? null)
            ? (int) $product['price_cents']
            : (is_numeric($product['unit_price_cents'] ?? null)
                ? (int) $product['unit_price_cents']
                : null);
        $line = [
            'sku' => $sku,
            'title' => (string) ($product['title'] ?? $product['product_title'] ?? "Product {$sku}"),
            'absolute_quantity' => $quantity,
            'unit_price_cents' => $unitPrice,
            'line_price_cents' => $unitPrice === null ? null : $unitPrice * $quantity,
        ];
        $lines = collect($this->basketLines)
            ->reject(fn (array $candidate): bool => (string) ($candidate['sku'] ?? '') === $sku)
            ->push($line)
            ->sortBy(fn ($candidate): string => (string) ($candidate['sku'] ?? ''))
            ->values()
            ->all();
        $this->basketLines = array_values($lines);
        $this->rememberLines([$line]);

        return [
            'product_id' => $sku,
            'absolute_quantity' => $quantity,
            'basket_checksum' => $this->checksum($this->basketInspection()),
        ];
    }

    /** @return array{lines: list<array<string, mixed>>, retailer_total_cents: int|null} */
    private function basketInspection(): array
    {
        $this->rememberLines($this->basketLines);
        $total = 0;
        $hasUnknownTotal = false;
        foreach ($this->basketLines as $line) {
            if (! is_numeric($line['line_price_cents'] ?? null)) {
                $hasUnknownTotal = true;
                break;
            }
            $total += (int) $line['line_price_cents'];
        }

        return [
            'lines' => array_values(collect($this->basketLines)
                ->sortBy(fn ($line): string => (string) ($line['sku'] ?? ''))
                ->values()
                ->all()),
            'retailer_total_cents' => $hasUnknownTotal ? null : $total,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function rememberLines(array $lines): void
    {
        foreach ($lines as $line) {
            $sku = (string) ($line['sku'] ?? '');
            if ($sku !== '') {
                $this->catalog[$sku] = $line;
            }
        }
    }

    /** @param array<array-key, mixed> $data */
    private function checksum(array $data): string
    {
        return hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
