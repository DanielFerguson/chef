<?php

namespace App\Jobs;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Models\BasketRun;
use App\Models\RetailerConnection;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProbeRetailerConnectionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly int $retailerConnectionId,
        public readonly int $basketRunId,
    ) {
        $this->onQueue('retailer');
    }

    public function uniqueId(): string
    {
        return 'retailer-auth-probe:'.$this->retailerConnectionId.':'.$this->basketRunId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('retailer-connection:'.$this->retailerConnectionId))
                ->shared()
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(RetailerAutomationGateway $gateway): void
    {
        /** @var array{context_id: string, connection: RetailerConnection, run: BasketRun}|null $state */
        $state = DB::transaction(function (): ?array {
            $connection = RetailerConnection::query()
                ->lockForUpdate()
                ->findOrFail($this->retailerConnectionId);
            $run = BasketRun::query()->lockForUpdate()->findOrFail($this->basketRunId);

            if ($connection->active_session_expires_at?->isPast()) {
                $connection->update([
                    'active_session_id' => null,
                    'active_session_claim_token' => null,
                    'active_session_purpose' => null,
                    'active_session_started_at' => null,
                    'active_session_expires_at' => null,
                ]);
            }

            $hasGrant = $connection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();
            if ($run->retailer_connection_id !== $connection->id
                || $run->team_id !== $connection->team_id
                || $run->status->isTerminal()
                || ! $hasGrant
                || $connection->status !== RetailerConnectionStatus::Connected
                || $connection->browserbase_context_id === null
                || $connection->active_session_id !== null
                || $connection->active_session_claim_token !== null) {
                return null;
            }

            return [
                'context_id' => $connection->browserbase_context_id,
                'connection' => $connection,
                'run' => $run,
            ];
        });

        if ($state === null) {
            return;
        }

        /** @var RetailerConnection $connection */
        $connection = $state['connection'];
        /** @var BasketRun $run */
        $run = $state['run'];

        $result = $gateway->execute(
            $state['context_id'],
            RetailerWorkerCommand::ProbeAuth,
        );
        $authenticationExpired = in_array($result->reasonCode, [
            'authentication_required',
            'session_expired',
        ], true) || Arr::get($result->data, 'authenticated') === false;

        if ($result->succeeded() && Arr::get($result->data, 'authenticated') === true) {
            DB::transaction(function () use ($connection): void {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                $hasGrant = $locked->grants()
                    ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($hasGrant && $locked->status === RetailerConnectionStatus::Connected) {
                    $locked->update([
                        'last_verified_at' => now(),
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);
                }
            });

            return;
        }

        if (! $authenticationExpired) {
            return;
        }

        DB::transaction(function () use ($connection, $run, $result): void {
            $lockedConnection = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $lockedRun = BasketRun::query()->lockForUpdate()->findOrFail($run->id);
            $hasGrant = $lockedConnection->grants()
                ->where('scope', RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
                ->whereNull('revoked_at')
                ->exists();

            if (! $hasGrant || $lockedRun->status->isTerminal()) {
                return;
            }

            $reasonCode = $result->reasonCode ?? 'authentication_required';
            $lockedConnection->update([
                'status' => RetailerConnectionStatus::ReauthenticationRequired,
                'failure_code' => $reasonCode,
                'failure_message' => 'Continue with Coles to reconnect the saved session.',
            ]);
            $lockedRun->update([
                'status' => BasketRunStatus::ReauthenticationRequired,
                'failure_code' => $reasonCode,
                'failure_message' => 'Continue with Coles before Chef prepares the basket.',
            ]);
        });
    }
}
