<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerWorkerCommand;
use App\Models\RetailerConnection;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseRetailerLiveSession
{
    public function __construct(
        private readonly ResumeRetailerConnectionAutomation $resumeAutomation,
    ) {}

    public function handle(
        RetailerConnection $connection,
        User $user,
        RetailerAutomationGateway $gateway,
    ): void {
        if (! $user->can('useLiveView', $connection)) {
            throw new AuthorizationException('Only the connected Coles account owner can close Live View.');
        }

        $contextId = $connection->browserbase_context_id;
        $sessionId = $connection->active_session_id;
        $claimToken = $connection->active_session_claim_token;

        if ($contextId === null) {
            return;
        }

        if ($sessionId === null) {
            $released = DB::transaction(function () use ($connection, $claimToken): bool {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->active_session_id !== null
                    || $locked->active_session_claim_token !== $claimToken) {
                    return false;
                }

                $locked->update([
                    'active_session_claim_token' => null,
                    'active_session_purpose' => null,
                    'active_session_started_at' => null,
                    'active_session_expires_at' => null,
                ]);

                return true;
            });

            if ($released) {
                $this->resumeAutomation->handle($connection);
            }

            return;
        }

        $result = $gateway->execute(
            $contextId,
            RetailerWorkerCommand::ReleaseSession,
            sessionId: $sessionId,
        );

        if (! $result->succeeded()) {
            throw ValidationException::withMessages([
                'connection' => 'The Coles Live View is still closing. Try again shortly.',
            ]);
        }

        $released = DB::transaction(function () use ($connection, $sessionId): bool {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->active_session_id !== $sessionId) {
                return false;
            }

            $locked->update([
                'active_session_id' => null,
                'active_session_claim_token' => null,
                'active_session_purpose' => null,
                'active_session_started_at' => null,
                'active_session_expires_at' => null,
            ]);

            return true;
        });

        if ($released) {
            $this->resumeAutomation->handle($connection);
        }
    }
}
