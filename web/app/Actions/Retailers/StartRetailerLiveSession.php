<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerWorkerCommand;
use App\Models\RetailerConnection;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerLiveSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartRetailerLiveSession
{
    public function __construct(
        private readonly ResumeRetailerConnectionAutomation $resumeAutomation,
    ) {}

    public function handle(
        RetailerConnection $connection,
        User $user,
        RetailerAutomationGateway $gateway,
        string $purpose,
    ): RetailerLiveSession {
        if (! $user->can('useLiveView', $connection)) {
            throw new AuthorizationException('Only the connected Coles account owner can open Live View.');
        }
        if (! in_array($purpose, ['authentication', 'review'], true)) {
            throw ValidationException::withMessages(['purpose' => 'This Live View purpose is not allowed.']);
        }
        if ($connection->browserbase_context_id === null) {
            throw ValidationException::withMessages(['connection' => 'Start a Coles connection first.']);
        }
        $claimToken = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($connection, $purpose, $claimToken): array {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->basketRuns()
                ->whereIn('status', collect(BasketRunStatus::cases())
                    ->filter(fn (BasketRunStatus $status): bool => $status->isActive()
                        && ($purpose === 'review' || $status !== BasketRunStatus::WaitingForConnection))
                    ->pluck('value'))
                ->exists()) {
                throw ValidationException::withMessages([
                    'basket' => 'Wait for Chef to stop automation before opening the Coles basket.',
                ]);
            }

            $claimInProgress = $locked->active_session_id === null
                && $locked->active_session_claim_token !== null
                && ($locked->active_session_expires_at === null
                    || $locked->active_session_expires_at->isFuture());
            $verificationInProgress = $locked->active_session_id !== null
                && $locked->active_session_purpose === 'verification';

            if ($claimInProgress || $verificationInProgress) {
                throw ValidationException::withMessages([
                    'connection' => 'Another Coles session is already opening. Try again shortly.',
                ]);
            }

            $state = [
                'context_id' => $locked->browserbase_context_id,
                'previous_session_id' => $locked->active_session_id,
                'previous_claim_token' => $locked->active_session_claim_token,
                'previous_purpose' => $locked->active_session_purpose,
                'previous_started_at' => $locked->active_session_started_at,
                'previous_expires_at' => $locked->active_session_expires_at,
            ];
            if ($state['context_id'] === null) {
                throw ValidationException::withMessages([
                    'connection' => 'Start a Coles connection first.',
                ]);
            }

            $locked->update([
                'active_session_id' => null,
                'active_session_claim_token' => $claimToken,
                'active_session_purpose' => $purpose,
                'active_session_started_at' => now(),
                'active_session_expires_at' => now()->addMinutes(2),
            ]);

            return $state;
        });

        $session = null;
        $previousSessionReleased = $claimed['previous_session_id'] === null;

        try {
            if ($claimed['previous_session_id'] !== null) {
                $released = $gateway->execute(
                    $claimed['context_id'],
                    RetailerWorkerCommand::ReleaseSession,
                    sessionId: $claimed['previous_session_id'],
                );
                if (! $released->succeeded()) {
                    throw ValidationException::withMessages([
                        'connection' => 'The previous Coles session is still closing. Try again shortly.',
                    ]);
                }
                $previousSessionReleased = true;
            }

            $session = $gateway->startLiveSession($claimed['context_id'], $purpose);
            DB::transaction(function () use ($connection, $session, $purpose, $claimToken): void {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->active_session_claim_token !== $claimToken) {
                    throw ValidationException::withMessages([
                        'connection' => 'Another Coles session replaced this request.',
                    ]);
                }

                $locked->update([
                    'active_session_id' => $session->sessionId,
                    'active_session_purpose' => $purpose,
                    'active_session_started_at' => now(),
                    'active_session_expires_at' => $session->expiresAt,
                ]);
            });

            return $session;
        } catch (Throwable $exception) {
            if ($session !== null) {
                try {
                    $gateway->execute(
                        $claimed['context_id'],
                        RetailerWorkerCommand::ReleaseSession,
                        sessionId: $session->sessionId,
                    );
                } catch (Throwable) {
                    // The hosted session has a short expiry and remains unusable to Chef.
                }
            }

            RetailerConnection::query()
                ->whereKey($connection->id)
                ->where('active_session_claim_token', $claimToken)
                ->update([
                    'active_session_id' => $previousSessionReleased
                        ? null
                        : $claimed['previous_session_id'],
                    'active_session_claim_token' => $previousSessionReleased
                        ? null
                        : $claimed['previous_claim_token'],
                    'active_session_purpose' => $previousSessionReleased
                        ? null
                        : $claimed['previous_purpose'],
                    'active_session_started_at' => $previousSessionReleased
                        ? null
                        : $claimed['previous_started_at'],
                    'active_session_expires_at' => $previousSessionReleased
                        ? null
                        : $claimed['previous_expires_at'],
                ]);

            $this->resumeAutomation->handle($connection);

            throw $exception;
        }
    }
}
