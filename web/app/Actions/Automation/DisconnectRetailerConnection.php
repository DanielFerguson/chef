<?php

namespace App\Actions\Automation;

use App\Actions\Retailer\CancelRetailerOrderRun;
use App\Automation\Contracts\BrowserSessionProvider;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisconnectRetailerConnection
{
    public function __construct(
        private readonly BrowserSessionProvider $provider,
        private readonly CancelRetailerOrderRun $cancelOrderRun,
    ) {}

    public function handle(RetailerConnection $connection, User $user): void
    {
        if (! $user->can('disconnect', $connection)) {
            throw new AuthorizationException('Only the connection owner can disconnect Woolworths.');
        }

        $cancellableStatuses = collect(RetailerOrderRunStatus::cases())
            ->reject(fn (RetailerOrderRunStatus $status): bool => $status->isTerminal() || $status->blocksResubmit())
            ->map->value
            ->all();

        foreach ($connection->orderRuns()->whereIn('status', $cancellableStatuses)->get() as $run) {
            $this->cancelOrderRun->handle($run, $user);
        }

        $sessions = $connection->browserSessions()
            ->whereNotIn('status', [BrowserSessionStatus::Closed->value, BrowserSessionStatus::Expired->value])
            ->get();

        foreach ($sessions as $session) {
            try {
                $this->provider->close($session);
            } catch (Throwable) {
                // Context deletion below is the final revocation boundary.
            }
        }

        $this->provider->deleteContext($connection);

        DB::transaction(function () use ($connection, $sessions): void {
            foreach ($sessions as $session) {
                $session->update(['status' => BrowserSessionStatus::Closed, 'ended_at' => now()]);
            }

            $connection->update([
                'provider_context_id' => null,
                'status' => RetailerConnectionStatus::Disconnected,
                'last_verified_at' => null,
                'disconnected_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);
        });
    }
}
