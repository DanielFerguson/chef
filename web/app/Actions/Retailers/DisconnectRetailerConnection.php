<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Models\RetailerConnection;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisconnectRetailerConnection
{
    public function handle(
        RetailerConnection $connection,
        User $user,
        RetailerAutomationGateway $gateway,
    ): RetailerConnection {
        if (! $user->can('delete', $connection)) {
            throw new AuthorizationException('Only the Coles account owner can disconnect it.');
        }

        /** @var array{context_id: string|null, session_id: string|null} $captured */
        $captured = DB::transaction(function () use ($connection): array {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $state = [
                'context_id' => $locked->browserbase_context_id,
                'session_id' => $locked->active_session_id,
            ];
            $locked->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $locked->basketRuns()
                ->whereIn('status', collect(BasketRunStatus::cases())
                    ->filter(fn (BasketRunStatus $status): bool => $status->isActive())
                    ->pluck('value'))
                ->update([
                    'status' => BasketRunStatus::WaitingForConnection->value,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);
            $locked->update([
                'status' => RetailerConnectionStatus::Disconnected,
                'active_session_purpose' => 'disconnecting',
                'disconnected_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $state;
        });

        try {
            if ($captured['context_id'] !== null) {
                if ($captured['session_id'] !== null) {
                    $gateway->execute(
                        $captured['context_id'],
                        RetailerWorkerCommand::ReleaseSession,
                        sessionId: $captured['session_id'],
                    );
                }
                $gateway->deleteContext($captured['context_id']);
            }
        } catch (Throwable $exception) {
            report($exception);
            RetailerConnection::query()
                ->whereKey($connection->id)
                ->where('status', RetailerConnectionStatus::Disconnected->value)
                ->update([
                    'failure_code' => 'context_cleanup_pending',
                    'failure_message' => 'The Coles connection is disconnected. Secure hosted-session cleanup will be retried before reconnecting.',
                ]);

            return $connection->refresh();
        }

        return DB::transaction(function () use ($connection, $captured): RetailerConnection {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status !== RetailerConnectionStatus::Disconnected
                || $locked->browserbase_context_id !== $captured['context_id']) {
                return $locked;
            }

            $locked->update([
                'browserbase_context_id' => null,
                'context_lookup_hash' => null,
                'active_session_id' => null,
                'active_session_claim_token' => null,
                'active_session_purpose' => null,
                'active_session_started_at' => null,
                'active_session_expires_at' => null,
            ]);

            return $locked->refresh();
        });
    }
}
