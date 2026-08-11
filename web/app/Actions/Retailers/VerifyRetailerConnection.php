<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Events\MealPlanRecipesReady;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyRetailerConnection
{
    public function handle(
        RetailerConnection $connection,
        User $user,
        RetailerAutomationGateway $gateway,
    ): RetailerConnection {
        if (! $user->can('update', $connection)) {
            throw new AuthorizationException('Only the connected Coles account owner can verify it.');
        }
        $claimed = $this->claimVerification($connection);

        try {
            $result = $gateway->execute(
                $claimed['context_id'],
                RetailerWorkerCommand::ProbeAuth,
                sessionId: $claimed['session_id'],
            );
        } catch (Throwable $exception) {
            $this->restoreVerificationClaim(
                $connection,
                $claimed,
                'authentication_verification_failed',
                'Chef could not verify the Coles sign-in. Try again.',
            );

            throw $exception;
        }

        if (! $result->succeeded() || Arr::get($result->data, 'authenticated') !== true) {
            $this->restoreVerificationClaim(
                $connection,
                $claimed,
                $result->reasonCode ?? 'authentication_not_verified',
                'Finish signing in to Coles, then ask Chef to verify again.',
            );

            throw ValidationException::withMessages([
                'connection' => 'Chef could not yet verify the Coles sign-in.',
            ]);
        }

        if ($claimed['session_id'] !== null) {
            try {
                $released = $gateway->execute(
                    $claimed['context_id'],
                    RetailerWorkerCommand::ReleaseSession,
                    sessionId: $claimed['session_id'],
                );
            } catch (Throwable $exception) {
                $this->restoreVerificationClaim(
                    $connection,
                    $claimed,
                    'authentication_session_release_failed',
                    'The Coles sign-in session is still closing. Try verification again shortly.',
                );

                throw $exception;
            }
            if (! $released->succeeded()) {
                $this->restoreVerificationClaim(
                    $connection,
                    $claimed,
                    'authentication_session_release_failed',
                    'The Coles sign-in session is still closing. Try verification again shortly.',
                );

                throw ValidationException::withMessages([
                    'connection' => 'The Coles sign-in session is still closing. Try again shortly.',
                ]);
            }
        }

        $runToResume = DB::transaction(function () use ($connection, $user, $claimed) {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status === RetailerConnectionStatus::Disconnected
                || $locked->browserbase_context_id !== $claimed['context_id']
                || $locked->active_session_id !== $claimed['session_id']
                || $locked->active_session_claim_token !== $claimed['claim_token']
                || $locked->active_session_purpose !== 'verification') {
                throw ValidationException::withMessages([
                    'connection' => 'The Coles sign-in session changed during verification. Try again.',
                ]);
            }

            $locked->update([
                'status' => RetailerConnectionStatus::Connected,
                'authenticated_at' => $locked->authenticated_at ?? now(),
                'last_verified_at' => now(),
                'active_session_id' => null,
                'active_session_claim_token' => null,
                'active_session_purpose' => null,
                'active_session_started_at' => null,
                'active_session_expires_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);
            $locked->grants()->firstOrCreate(
                [
                    'scope' => RetailerAutomationScope::ReplaceBasketAfterPlanApproval,
                    'revoked_at' => null,
                ],
                [
                    'team_id' => $locked->team_id,
                    'owner_user_id' => $user->id,
                    'disclosure_version' => config('retailer.consent.disclosure_version'),
                    'disclosure_hash' => hash('sha256', config('retailer.consent.disclosure')),
                    'granted_at' => now(),
                ],
            );
            $waitingRuns = $locked->team->basketRuns()
                ->where('status', BasketRunStatus::WaitingForConnection)
                ->latest('id')
                ->get();
            $run = $waitingRuns->shift();

            if ($waitingRuns->isNotEmpty()) {
                $locked->team->basketRuns()
                    ->whereKey($waitingRuns->modelKeys())
                    ->update(['status' => BasketRunStatus::Cancelled->value]);
            }
            if ($run !== null) {
                $run->update([
                    'retailer_connection_id' => $locked->id,
                    'status' => $run->grocery_plan_id === null
                        ? BasketRunStatus::WaitingForRecipes
                        : BasketRunStatus::DiscoveringProducts,
                ]);
            }

            return $run;
        });

        if ($runToResume?->grocery_plan_id !== null
            && config('retailer.features.discovery', false)) {
            DiscoverRetailerProductsJob::dispatch($runToResume->id);
        } elseif ($runToResume !== null && $this->recipesReady($runToResume->mealPlan)) {
            MealPlanRecipesReady::dispatch($runToResume->meal_plan_id);
        }

        return $connection->refresh();
    }

    /**
     * @return array{
     *     context_id: string,
     *     session_id: string|null,
     *     claim_token: string,
     *     previous_purpose: string|null,
     *     previous_started_at: Carbon|null,
     *     previous_expires_at: Carbon|null
     * }
     */
    private function claimVerification(RetailerConnection $connection): array
    {
        return DB::transaction(function () use ($connection): array {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->browserbase_context_id === null) {
                throw ValidationException::withMessages(['connection' => 'The Coles Context is unavailable.']);
            }
            if ($locked->basketRuns()
                ->whereIn('status', collect(BasketRunStatus::cases())
                    ->filter(fn (BasketRunStatus $status): bool => $status->isActive()
                        && $status !== BasketRunStatus::WaitingForConnection)
                    ->pluck('value'))
                ->exists()) {
                throw ValidationException::withMessages([
                    'connection' => 'Wait for Chef to stop Coles automation before verifying sign-in.',
                ]);
            }

            $actorIsActive = $locked->active_session_claim_token !== null
                && ($locked->active_session_expires_at === null
                    || $locked->active_session_expires_at->isFuture());
            if ($locked->active_session_id !== null
                && $locked->active_session_purpose !== 'authentication') {
                throw ValidationException::withMessages([
                    'connection' => 'Close Coles Live View before verifying sign-in.',
                ]);
            }
            if ($actorIsActive && $locked->active_session_id === null) {
                throw ValidationException::withMessages([
                    'connection' => 'Another Coles session operation is still in progress. Try again shortly.',
                ]);
            }

            $claimToken = $locked->active_session_claim_token ?? (string) Str::uuid();
            $state = [
                'context_id' => $locked->browserbase_context_id,
                'session_id' => $locked->active_session_id,
                'claim_token' => $claimToken,
                'previous_purpose' => $locked->active_session_purpose,
                'previous_started_at' => $locked->active_session_started_at,
                'previous_expires_at' => $locked->active_session_expires_at,
            ];
            $locked->update([
                'active_session_claim_token' => $claimToken,
                'active_session_purpose' => 'verification',
                'active_session_started_at' => now(),
                'active_session_expires_at' => now()->addMinutes(5),
            ]);

            return $state;
        });
    }

    /**
     * @param array{
     *     context_id: string,
     *     session_id: string|null,
     *     claim_token: string,
     *     previous_purpose: string|null,
     *     previous_started_at: Carbon|null,
     *     previous_expires_at: Carbon|null
     * } $claimed
     */
    private function restoreVerificationClaim(
        RetailerConnection $connection,
        array $claimed,
        string $failureCode,
        string $failureMessage,
    ): void {
        DB::transaction(function () use ($connection, $claimed, $failureCode, $failureMessage): void {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($locked->status === RetailerConnectionStatus::Disconnected
                || $locked->active_session_claim_token !== $claimed['claim_token']
                || $locked->active_session_purpose !== 'verification') {
                return;
            }

            $locked->update([
                'status' => RetailerConnectionStatus::PendingAuthentication,
                'active_session_id' => $claimed['session_id'],
                'active_session_claim_token' => $claimed['session_id'] === null
                    ? null
                    : $claimed['claim_token'],
                'active_session_purpose' => $claimed['session_id'] === null
                    ? null
                    : ($claimed['previous_purpose'] ?? 'authentication'),
                'active_session_started_at' => $claimed['session_id'] === null
                    ? null
                    : $claimed['previous_started_at'],
                'active_session_expires_at' => $claimed['session_id'] === null
                    ? null
                    : $claimed['previous_expires_at'],
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ]);
        });
    }

    private function recipesReady(MealPlan $mealPlan): bool
    {
        return $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->doesntExist();
    }
}
