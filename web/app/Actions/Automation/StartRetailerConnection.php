<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Models\BrowserSession;
use App\Models\Retailer;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartRetailerConnection
{
    public function __construct(
        private readonly BrowserSessionProvider $provider,
        private readonly RetailerCartAdapter $adapter,
        private readonly CreateBrowserSession $createSession,
        private readonly CloseBrowserSession $closeSession,
    ) {}

    public function handle(Team $team, User $user): BrowserSession
    {
        if (! (bool) config('automation.connection_enabled')) {
            throw ValidationException::withMessages(['connection' => 'Woolworths connection is not enabled in this environment.']);
        }

        if (! $user->memberships()->where('team_id', $team->id)->exists()) {
            throw new AuthorizationException('You cannot connect a retailer for this family.');
        }

        $retailer = Retailer::query()->where('slug', $this->adapter->retailerSlug())->where('active', true)->firstOrFail();
        $connection = RetailerConnection::query()->firstOrCreate(
            [
                'team_id' => $team->id,
                'retailer_id' => $retailer->id,
                'owner_user_id' => $user->id,
                'provider' => 'browserbase',
            ],
            ['status' => RetailerConnectionStatus::PendingLogin],
        );

        if (! $user->can('authenticate', $connection)) {
            throw new AuthorizationException('Only the connection owner can sign in to Woolworths.');
        }

        $activeSession = $connection->browserSessions()
            ->whereIn('status', [BrowserSessionStatus::HumanControl->value, BrowserSessionStatus::Creating->value])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->first();

        if ($activeSession !== null) {
            return $activeSession;
        }

        if ($connection->provider_context_id === null) {
            try {
                $connection->update(['provider_context_id' => $this->provider->createContext()]);
            } catch (Throwable $exception) {
                $connection->update(['status' => RetailerConnectionStatus::Error]);

                throw $exception;
            }
        }

        $purpose = $connection->status === RetailerConnectionStatus::ReauthenticationRequired
            ? BrowserSessionPurpose::Reauthentication
            : BrowserSessionPurpose::Login;
        $previousStatus = $connection->status;
        $connection->update(['status' => RetailerConnectionStatus::PendingLogin, 'disconnected_at' => null]);

        $session = null;

        try {
            $session = $this->createSession->handle($connection, $purpose);
            $this->adapter->openLogin($session);

            return $session;
        } catch (Throwable $exception) {
            if ($session !== null) {
                try {
                    $this->closeSession->handle($session);
                } catch (Throwable) {
                    // The provider session will expire at its bounded deadline;
                    // retain no transient URL while surfacing the original error.
                }
            }

            DB::transaction(function () use ($connection, $previousStatus, $session, $exception): void {
                if ($exception instanceof RetailerContextRevokedException) {
                    $connection->update([
                        'provider_context_id' => null,
                        'status' => RetailerConnectionStatus::Revoked,
                    ]);

                    return;
                }

                $connection->update([
                    'status' => $session === null && $exception instanceof ValidationException
                        ? $previousStatus
                        : RetailerConnectionStatus::Error,
                ]);
            });

            throw $exception;
        }
    }
}
