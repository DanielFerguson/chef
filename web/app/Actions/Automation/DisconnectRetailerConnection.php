<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisconnectRetailerConnection
{
    public function __construct(
        private readonly BrowserSessionProvider $provider,
        private readonly CancelAutomationRun $cancelRun,
    ) {}

    public function handle(RetailerConnection $connection, User $user): void
    {
        if (! $user->can('disconnect', $connection)) {
            throw new AuthorizationException('Only the connection owner can disconnect Woolworths.');
        }

        $activeStatus = collect(AutomationRunStatus::cases())->reject->isTerminal()->map->value->all();

        foreach ($connection->runs()->whereIn('status', $activeStatus)->get() as $run) {
            $this->cancelRun->handle($run, $user);
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
