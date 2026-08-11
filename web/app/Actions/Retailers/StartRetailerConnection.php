<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerLiveSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartRetailerConnection
{
    public function __construct(private readonly StartRetailerLiveSession $startLiveSession) {}

    /** @return array{connection: RetailerConnection, session: RetailerLiveSession} */
    public function handle(
        Team $team,
        User $user,
        RetailerAutomationGateway $gateway,
    ): array {
        if (! config('retailer.features.experience', false)) {
            throw ValidationException::withMessages([
                'connection' => 'The Coles basket beta is not enabled for this household.',
            ]);
        }
        if (! $user->can('create', [RetailerConnection::class, $team])) {
            throw new AuthorizationException('You cannot connect a retailer for this household.');
        }

        $connection = RetailerConnection::query()
            ->whereBelongsTo($team)
            ->where('provider', RetailerProvider::Coles)
            ->first();

        if ($connection !== null && $connection->owner_user_id !== $user->id) {
            throw new AuthorizationException('Another household member owns the connected Coles account.');
        }

        if ($connection?->status === RetailerConnectionStatus::ReauthenticationRequired) {
            DB::transaction(function () use ($connection): void {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->status !== RetailerConnectionStatus::ReauthenticationRequired) {
                    return;
                }

                $locked->basketRuns()
                    ->whereIn('status', collect(BasketRunStatus::cases())
                        ->filter(fn (BasketRunStatus $status): bool => $status->isActive())
                        ->pluck('value'))
                    ->update([
                        'status' => BasketRunStatus::ReauthenticationRequired->value,
                        'failure_code' => 'authentication_required',
                        'failure_message' => 'Continue with Coles before Chef prepares the basket.',
                    ]);
            });
        }

        if ($connection?->status === RetailerConnectionStatus::Disconnected
            && $connection->browserbase_context_id !== null) {
            try {
                $gateway->deleteContext($connection->browserbase_context_id);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'connection' => 'Chef is still securely removing the previous Coles connection. Try again shortly.',
                ]);
            }

            DB::transaction(function () use ($connection): void {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->status !== RetailerConnectionStatus::Disconnected) {
                    return;
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
            });
            $connection->refresh();
        }

        [$connection, $contextClaimToken] = $this->claimContextProvisioning($team, $user);
        $createdContext = null;

        if ($contextClaimToken !== null) {
            try {
                $createdContext = $gateway->createContext();
                $connection = DB::transaction(function () use ($connection, $contextClaimToken, $createdContext): RetailerConnection {
                    $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                    if ($locked->active_session_claim_token !== $contextClaimToken
                        || $locked->active_session_purpose !== 'context_provisioning'
                        || $locked->status === RetailerConnectionStatus::Disconnected
                        || $locked->browserbase_context_id !== null) {
                        throw ValidationException::withMessages([
                            'connection' => 'The Coles connection changed while its secure Context was opening. Try again.',
                        ]);
                    }

                    $locked->update([
                        'status' => RetailerConnectionStatus::PendingAuthentication,
                        'browserbase_context_id' => $createdContext,
                        'context_lookup_hash' => hash('sha256', $createdContext),
                        'active_session_claim_token' => null,
                        'active_session_purpose' => null,
                        'active_session_started_at' => null,
                        'active_session_expires_at' => null,
                        'disconnected_at' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                    ]);

                    return $locked->refresh();
                });
            } catch (Throwable $exception) {
                if ($createdContext !== null) {
                    $this->deleteCreatedContext(
                        $connection,
                        $gateway,
                        $createdContext,
                        'context_provisioning_failed',
                        $contextClaimToken,
                    );
                } else {
                    RetailerConnection::query()
                        ->whereKey($connection->id)
                        ->where('active_session_claim_token', $contextClaimToken)
                        ->update([
                            'active_session_claim_token' => null,
                            'active_session_purpose' => null,
                            'active_session_started_at' => null,
                            'active_session_expires_at' => null,
                            'status' => RetailerConnectionStatus::Failed->value,
                            'failure_code' => 'context_provisioning_failed',
                            'failure_message' => 'Chef could not create a secure Coles Context.',
                        ]);
                }

                throw $exception;
            }
        }

        $session = $this->startLiveSession->handle(
            $connection,
            $user,
            $gateway,
            'authentication',
        );
        DB::transaction(function () use ($connection, $session): void {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status === RetailerConnectionStatus::Disconnected
                || $locked->active_session_id !== $session->sessionId) {
                throw ValidationException::withMessages([
                    'connection' => 'The Coles connection changed while sign-in was opening. Try again.',
                ]);
            }

            $locked->update([
                'status' => RetailerConnectionStatus::PendingAuthentication,
                'failure_code' => null,
                'failure_message' => null,
            ]);
        });

        return [
            'connection' => $connection->refresh(),
            'session' => $session,
        ];
    }

    /** @return array{RetailerConnection, string|null} */
    private function claimContextProvisioning(Team $team, User $user): array
    {
        return DB::transaction(function () use ($team, $user): array {
            $connection = RetailerConnection::query()->firstOrCreate(
                [
                    'team_id' => $team->id,
                    'provider' => RetailerProvider::Coles,
                ],
                [
                    'owner_user_id' => $user->id,
                    'status' => RetailerConnectionStatus::PendingAuthentication,
                ],
            );
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->owner_user_id !== $user->id) {
                throw new AuthorizationException('Another household member owns the connected Coles account.');
            }

            if ($locked->browserbase_context_id !== null) {
                return [$locked, null];
            }

            $provisioningInProgress = $locked->active_session_claim_token !== null
                && $locked->active_session_purpose === 'context_provisioning'
                && ($locked->active_session_expires_at === null || $locked->active_session_expires_at->isFuture());
            if ($provisioningInProgress) {
                throw ValidationException::withMessages([
                    'connection' => 'Another Coles connection is already opening. Try again shortly.',
                ]);
            }

            $claimToken = (string) Str::uuid();
            $locked->update([
                'owner_user_id' => $user->id,
                'status' => RetailerConnectionStatus::PendingAuthentication,
                'active_session_id' => null,
                'active_session_claim_token' => $claimToken,
                'active_session_purpose' => 'context_provisioning',
                'active_session_started_at' => now(),
                'active_session_expires_at' => now()->addMinutes(2),
                'disconnected_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return [$locked->refresh(), $claimToken];
        });
    }

    private function deleteCreatedContext(
        RetailerConnection $connection,
        RetailerAutomationGateway $gateway,
        string $createdContext,
        string $failureCode,
        ?string $contextClaimToken = null,
    ): void {
        $contextLookupHash = hash('sha256', $createdContext);

        try {
            $gateway->deleteContext($createdContext);
        } catch (Throwable $exception) {
            report($exception);

            DB::transaction(function () use ($connection, $createdContext, $contextLookupHash, $contextClaimToken): void {
                $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
                if ($locked->context_lookup_hash === $contextLookupHash) {
                    $locked->update([
                        'status' => $locked->status === RetailerConnectionStatus::Disconnected
                            ? RetailerConnectionStatus::Disconnected
                            : RetailerConnectionStatus::Failed,
                        'failure_code' => 'context_cleanup_pending',
                        'failure_message' => 'Chef could not finish removing an unused Coles Context. Try again shortly.',
                    ]);

                    return;
                }
                if ($contextClaimToken === null
                    || $locked->active_session_claim_token !== $contextClaimToken
                    || $locked->browserbase_context_id !== null) {
                    return;
                }

                $locked->update([
                    'browserbase_context_id' => $createdContext,
                    'context_lookup_hash' => $contextLookupHash,
                    'active_session_claim_token' => null,
                    'active_session_purpose' => null,
                    'active_session_started_at' => null,
                    'active_session_expires_at' => null,
                    'status' => RetailerConnectionStatus::Disconnected,
                    'disconnected_at' => $locked->disconnected_at ?? now(),
                    'failure_code' => 'context_cleanup_pending',
                    'failure_message' => 'Chef could not finish removing an unused Coles Context. Try again shortly.',
                ]);
            });

            return;
        }

        DB::transaction(function () use ($connection, $contextLookupHash, $contextClaimToken, $failureCode): void {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $ownsStoredContext = $locked->context_lookup_hash === $contextLookupHash;
            $ownsProvisioningClaim = $contextClaimToken !== null
                && $locked->active_session_claim_token === $contextClaimToken
                && $locked->browserbase_context_id === null;
            if (! $ownsStoredContext && ! $ownsProvisioningClaim) {
                return;
            }

            $locked->update([
                'browserbase_context_id' => null,
                'context_lookup_hash' => null,
                'active_session_id' => null,
                'active_session_claim_token' => null,
                'active_session_purpose' => null,
                'active_session_started_at' => null,
                'active_session_expires_at' => null,
                'status' => $locked->status === RetailerConnectionStatus::Disconnected
                    ? RetailerConnectionStatus::Disconnected
                    : RetailerConnectionStatus::Failed,
                'failure_code' => $locked->status === RetailerConnectionStatus::Disconnected
                    ? null
                    : $failureCode,
                'failure_message' => $locked->status === RetailerConnectionStatus::Disconnected
                    ? null
                    : 'Chef could not open a secure Coles sign-in session.',
            ]);
        });
    }
}
