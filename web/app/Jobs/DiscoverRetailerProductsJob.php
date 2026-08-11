<?php

namespace App\Jobs;

use App\Actions\Retailers\ValidateRetailerProductCandidate;
use App\Ai\Contracts\RetailerSearchRecovery;
use App\Ai\Data\RetailerSearchRecoveryRequest;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\GrocerySearchMethod;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Models\BasketRun;
use App\Models\GroceryRequirement;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerWorkerResult;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DiscoverRetailerProductsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $basketRunId)
    {
        $this->onQueue('retailer');
    }

    public function uniqueId(): string
    {
        return 'retailer-discovery:'.$this->basketRunId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $connectionId = BasketRun::query()
            ->whereKey($this->basketRunId)
            ->value('retailer_connection_id');

        return [
            (new WithoutOverlapping('retailer-connection:'.($connectionId ?? 'run-'.$this->basketRunId)))
                ->shared()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        RetailerAutomationGateway $gateway,
        ValidateRetailerProductCandidate $validateCandidate,
        RetailerSearchRecovery $searchRecovery,
    ): void {
        if (! config('retailer.features.discovery', false)) {
            return;
        }

        $run = $this->loadRun();
        if ($run->status->isTerminal()) {
            return;
        }
        if ($run->connection === null
            || $run->connection->status !== RetailerConnectionStatus::Connected
            || $run->connection->browserbase_context_id === null) {
            $run->update(['status' => BasketRunStatus::ReauthenticationRequired]);

            return;
        }
        $hasGrant = $run->connection->grants()
            ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
            ->whereNull('revoked_at')
            ->exists();
        if (! $hasGrant) {
            $run->update([
                'status' => BasketRunStatus::WaitingForConnection,
                'failure_code' => 'standing_consent_required',
                'failure_message' => 'The Coles account owner must allow basket preparation before Chef continues.',
            ]);

            return;
        }
        $claimToken = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($run, $claimToken): bool {
            $lockedConnection = RetailerConnection::query()
                ->lockForUpdate()
                ->findOrFail($run->retailer_connection_id);
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($lockedConnection->active_session_expires_at?->isPast()) {
                $lockedConnection->update([
                    'active_session_id' => null,
                    'active_session_claim_token' => null,
                    'active_session_purpose' => null,
                    'active_session_started_at' => null,
                    'active_session_expires_at' => null,
                ]);
            }
            $hasGrant = $lockedConnection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();
            if ($locked->retailer_connection_id !== $lockedConnection->id
                || $locked->team_id !== $lockedConnection->team_id
                || $locked->status->isTerminal()
                || $lockedConnection->status !== RetailerConnectionStatus::Connected
                || $lockedConnection->browserbase_context_id === null
                || ! $hasGrant
                || $lockedConnection->active_session_id !== null
                || $lockedConnection->active_session_claim_token !== null) {
                return false;
            }
            if ($locked->claim_token !== null && $locked->claimed_at?->isAfter(now()->subMinutes(5))) {
                return false;
            }

            $locked->update([
                'status' => BasketRunStatus::DiscoveringProducts,
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            $requirements = $run->groceryPlan->requirements->sortBy('id')->values();
            $initialSearches = $this->initialSearches($requirements);
            if ($initialSearches !== [] && ! $this->executeSearches(
                $run,
                $claimToken,
                $gateway,
                $validateCandidate,
                $initialSearches,
            )) {
                return;
            }

            $run = $this->loadRun();
            $unresolved = $run->groceryPlan->requirements->filter(
                fn (GroceryRequirement $requirement): bool => $requirement->candidates
                    ->where('status', RetailerCandidateStatus::Eligible)
                    ->isEmpty()
                    && $requirement->searchAttempts->count() < 3,
            )->sortBy('id')->values();
            if ($unresolved->isNotEmpty()) {
                $finalSearches = $this->finalSearches($run, $unresolved, $searchRecovery);
                if (! $this->executeSearches(
                    $run,
                    $claimToken,
                    $gateway,
                    $validateCandidate,
                    $finalSearches,
                )) {
                    return;
                }
            }

            if ($this->finishDiscovery($run, $claimToken)) {
                SelectRetailerProductsJob::dispatch($run->id);
            }
        } catch (Throwable $exception) {
            BasketRun::query()
                ->whereKey($run)
                ->where('claim_token', $claimToken)
                ->update(['claim_token' => null, 'claimed_at' => null]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        BasketRun::query()
            ->whereKey($this->basketRunId)
            ->where('status', BasketRunStatus::DiscoveringProducts->value)
            ->update([
                'status' => BasketRunStatus::Failed->value,
                'failure_code' => 'product_discovery_failed',
                'failure_message' => 'Chef stopped after it could not finish finding Coles products. Try preparing the basket again.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);
    }

    private function loadRun(): BasketRun
    {
        return BasketRun::query()
            ->with([
                'connection',
                'groceryPlan.requirements.candidates',
                'groceryPlan.requirements.searchAttempts',
            ])
            ->findOrFail($this->basketRunId);
    }

    /**
     * @param  Collection<int, GroceryRequirement>  $requirements
     * @return list<array<string, mixed>>
     */
    private function initialSearches(Collection $requirements): array
    {
        $searches = [];

        foreach ($requirements as $requirement) {
            if ($requirement->candidates->where('status', RetailerCandidateStatus::Eligible)->isNotEmpty()) {
                continue;
            }

            $attempted = $requirement->searchAttempts->pluck('query')
                ->map(fn (string $query): string => mb_strtolower($query));
            $availableSlots = max(0, 2 - $requirement->searchAttempts->count());

            $definitions = collect(array_slice($requirement->search_queries, 0, 2))
                ->map(fn (string $query): string => Str::squish($query))
                ->filter(fn (string $query): bool => $query !== '' && ! $attempted->contains(mb_strtolower($query)))
                ->take($availableSlots)
                ->values()
                ->map(fn (string $query, int $index): array => $this->searchDefinition(
                    $requirement,
                    $requirement->searchAttempts->count() + $index + 1,
                    GrocerySearchMethod::Deterministic,
                    $query,
                ))
                ->all();
            foreach ($definitions as $definition) {
                $searches[] = $definition;
            }
        }

        return $searches;
    }

    /**
     * @param  Collection<int, GroceryRequirement>  $requirements
     * @return list<array<string, mixed>>
     */
    private function finalSearches(
        BasketRun $run,
        Collection $requirements,
        RetailerSearchRecovery $searchRecovery,
    ): array {
        $queries = null;
        if (config('retailer.features.ai_recovery', false)) {
            try {
                $recoveryRequirements = [];
                foreach ($requirements as $requirement) {
                    $attemptedQueries = [];
                    foreach ($requirement->searchAttempts->sortBy('sequence') as $attempt) {
                        $attemptedQueries[] = $attempt->query;
                    }
                    $recoveryRequirements[] = [
                        'requirement_id' => $requirement->id,
                        'name' => $requirement->display_name,
                        'form' => $requirement->normalized_form,
                        'attempted_queries' => $attemptedQueries,
                    ];
                }
                $result = $searchRecovery->recover(new RetailerSearchRecoveryRequest(
                    teamId: $run->team_id,
                    groceryPlanId: $run->grocery_plan_id,
                    requirements: $recoveryRequirements,
                ));
                $queries = $this->validatedRecoveryQueries($requirements, $result->queries);
            } catch (Throwable) {
                $queries = null;
            }
        }

        $searches = [];
        foreach ($requirements as $requirement) {
            $query = $queries[$requirement->id] ?? $this->deterministicBroadQuery($requirement);
            $method = isset($queries[$requirement->id])
                ? GrocerySearchMethod::AiRecovery
                : GrocerySearchMethod::DeterministicFallback;

            $searches[] = $this->searchDefinition(
                $requirement,
                $requirement->searchAttempts->count() + 1,
                $method,
                $query,
            );
        }

        return $searches;
    }

    /**
     * @param  Collection<int, GroceryRequirement>  $requirements
     * @param  list<array{requirement_id: int, query: string}>  $queries
     * @return array<int, string>|null
     */
    private function validatedRecoveryQueries(Collection $requirements, array $queries): ?array
    {
        $expectedIds = $requirements->pluck('id')->sort()->values()->all();
        $actualIds = array_map(fn (array $query): int => $query['requirement_id'], $queries);
        $sortedIds = $actualIds;
        sort($sortedIds);
        if (count($actualIds) !== count(array_unique($actualIds)) || $sortedIds !== $expectedIds) {
            return null;
        }

        $validated = [];
        foreach ($queries as $queryData) {
            $requirement = $requirements->firstWhere('id', (int) $queryData['requirement_id']);
            $query = Str::squish($queryData['query']);
            $attempted = $requirement->searchAttempts
                ->contains(fn ($attempt): bool => mb_strtolower($attempt->query) === mb_strtolower($query));

            if ($query === ''
                || mb_strlen($query) > 80
                || preg_match('/(?:https?:\/\/|www\.)/iu', $query) === 1
                || $attempted) {
                return null;
            }
            $validated[$requirement->id] = $query;
        }

        return $validated;
    }

    private function deterministicBroadQuery(GroceryRequirement $requirement): string
    {
        $query = Str::squish((string) ($requirement->search_queries[2] ?? $requirement->normalized_name));

        return Str::limit($query === '' ? $requirement->display_name : $query, 80, '');
    }

    /** @return array<string, mixed> */
    private function searchDefinition(
        GroceryRequirement $requirement,
        int $sequence,
        GrocerySearchMethod $method,
        string $query,
    ): array {
        return [
            'requirement' => $requirement,
            'sequence' => min(3, $sequence),
            'method' => $method,
            'query' => Str::limit(Str::squish($query), 80, ''),
        ];
    }

    /** @param list<array<string, mixed>> $searches */
    private function executeSearches(
        BasketRun $run,
        string $claimToken,
        RetailerAutomationGateway $gateway,
        ValidateRetailerProductCandidate $validateCandidate,
        array $searches,
    ): bool {
        if ($searches === []) {
            return true;
        }
        if (! $this->holdsDiscoveryClaim($run, $claimToken)) {
            return false;
        }

        $grouped = collect($searches)->groupBy(
            fn (array $search): int => $search['requirement']->id,
        );
        $requirements = $grouped->map(function (Collection $requirementSearches): array {
            /** @var GroceryRequirement $requirement */
            $requirement = $requirementSearches->first()['requirement'];

            return [
                'requirement_id' => $requirement->id,
                'name' => $requirement->display_name,
                'form' => $requirement->normalized_form,
                'quantity' => $requirement->quantity,
                'unit' => $requirement->unit,
                'quantity_unknown' => $requirement->quantity_unknown,
                'queries' => $requirementSearches->pluck('query')->values()->all(),
                'constraints' => $requirement->applicable_constraints ?? [],
            ];
        })->values()->all();
        $result = $gateway->execute(
            $run->connection->browserbase_context_id,
            RetailerWorkerCommand::SearchProducts,
            ['requirements' => $requirements],
        );
        if (! $this->holdsDiscoveryClaim($run, $claimToken)) {
            return false;
        }

        if ($result->status === RetailerWorkerResultStatus::Retryable) {
            throw new RuntimeException('Retailer discovery is temporarily unavailable.');
        }
        if (! $result->succeeded()) {
            $this->recordWorkerFailure($run, $claimToken, $result->status, $result->reasonCode);

            return false;
        }

        $candidates = Arr::get($result->data, 'candidates', []);
        if (! is_array($candidates)) {
            throw new RuntimeException('Retailer discovery returned invalid candidates.');
        }

        $requirementsById = collect($searches)
            ->map(fn (array $search): GroceryRequirement => $search['requirement'])
            ->keyBy('id');
        $counts = [];
        DB::transaction(function () use ($run, $requirementsById, $candidates, $validateCandidate, $searches, $result, &$counts): void {
            foreach ($candidates as $candidateData) {
                if (! is_array($candidateData)) {
                    continue;
                }

                $requirement = $requirementsById->get((int) Arr::get($candidateData, 'requirement_id'));
                if (! $requirement instanceof GroceryRequirement) {
                    continue;
                }

                $validated = $validateCandidate->handle($requirement, $candidateData);
                $requirement->candidates()->updateOrCreate(
                    [
                        'provider' => $validated['provider'],
                        'sku' => $validated['sku'],
                    ],
                    [
                        'team_id' => $run->team_id,
                        ...$validated,
                    ],
                );
                $counts[$requirement->id]['result'] = ($counts[$requirement->id]['result'] ?? 0) + 1;
                if ($validated['status'] === RetailerCandidateStatus::Eligible) {
                    $counts[$requirement->id]['eligible'] = ($counts[$requirement->id]['eligible'] ?? 0) + 1;
                }
                $requirement->update(['status' => GroceryRequirementStatus::Discovering]);
            }

            foreach ($searches as $search) {
                /** @var GroceryRequirement $requirement */
                $requirement = $search['requirement'];
                $attemptData = $this->workerAttempt($result, $requirement->id, $search['query']);
                $requirement->searchAttempts()->updateOrCreate(
                    ['sequence' => $search['sequence']],
                    [
                        'team_id' => $run->team_id,
                        'method' => $search['method'],
                        'query' => $search['query'],
                        'result_count' => $attemptData['result_count'] ?? ($counts[$requirement->id]['result'] ?? 0),
                        'eligible_result_count' => $attemptData['eligible_result_count'] ?? ($counts[$requirement->id]['eligible'] ?? 0),
                        'reason_code' => $this->safeReasonCode($attemptData['reason_code'] ?? null),
                        'captured_at' => now(),
                    ],
                );
            }
        });

        return true;
    }

    /** @return array<string, mixed> */
    private function workerAttempt(RetailerWorkerResult $result, int $requirementId, string $query): array
    {
        $attempts = Arr::get($result->data, 'attempts', []);
        if (! is_array($attempts)) {
            return [];
        }

        foreach ($attempts as $attempt) {
            if (is_array($attempt)
                && (int) Arr::get($attempt, 'requirement_id') === $requirementId
                && (string) Arr::get($attempt, 'query') === $query) {
                return $attempt;
            }
        }

        return [];
    }

    private function safeReasonCode(mixed $reasonCode): ?string
    {
        if (! is_string($reasonCode) || preg_match('/^[a-z0-9_]{1,80}$/', $reasonCode) !== 1) {
            return null;
        }

        return $reasonCode;
    }

    private function recordWorkerFailure(
        BasketRun $run,
        string $claimToken,
        RetailerWorkerResultStatus $status,
        ?string $reasonCode,
    ): void {
        $runStatus = match ($reasonCode) {
            'authentication_required', 'session_expired' => BasketRunStatus::ReauthenticationRequired,
            default => $status === RetailerWorkerResultStatus::Uncertain
                ? BasketRunStatus::Uncertain
                : BasketRunStatus::Failed,
        };
        BasketRun::query()
            ->whereKey($run)
            ->where('status', BasketRunStatus::DiscoveringProducts->value)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => $runStatus->value,
                'failure_code' => $this->safeReasonCode($reasonCode) ?? 'retailer_discovery_failed',
                'failure_message' => 'Chef could not safely inspect Coles products.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);
    }

    /** @phpstan-impure */
    private function holdsDiscoveryClaim(BasketRun $run, string $claimToken): bool
    {
        return DB::transaction(function () use ($run, $claimToken): bool {
            $connection = RetailerConnection::query()
                ->lockForUpdate()
                ->findOrFail($run->retailer_connection_id);
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($run->id);
            $hasGrant = $connection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();
            $valid = $locked->status === BasketRunStatus::DiscoveringProducts
                && $locked->claim_token === $claimToken
                && $connection->status === RetailerConnectionStatus::Connected
                && $connection->browserbase_context_id !== null
                && $connection->active_session_id === null
                && $connection->active_session_claim_token === null
                && $hasGrant;

            if (! $valid && $locked->claim_token === $claimToken) {
                $locked->update([
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);
            }

            return $valid;
        });
    }

    private function finishDiscovery(BasketRun $run, string $claimToken): bool
    {
        return DB::transaction(function () use ($run, $claimToken): bool {
            $connection = RetailerConnection::query()
                ->lockForUpdate()
                ->findOrFail($run->retailer_connection_id);
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($run->id);
            $hasGrant = $connection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();

            if ($locked->status !== BasketRunStatus::DiscoveringProducts
                || $locked->claim_token !== $claimToken
                || $connection->status !== RetailerConnectionStatus::Connected
                || $connection->browserbase_context_id === null
                || $connection->active_session_id !== null
                || $connection->active_session_claim_token !== null
                || ! $hasGrant) {
                if ($locked->claim_token === $claimToken) {
                    $locked->update([
                        'claim_token' => null,
                        'claimed_at' => null,
                    ]);
                }

                return false;
            }

            $locked->update([
                'status' => BasketRunStatus::SelectingProducts,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            return true;
        });
    }
}
