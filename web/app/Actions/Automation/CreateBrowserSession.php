<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Models\AutomationRun;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use Throwable;

class CreateBrowserSession
{
    public function __construct(
        private readonly BrowserSessionProvider $provider,
        private readonly AcquireRetailerConnectionLease $acquireLease,
        private readonly ReleaseRetailerConnectionLease $releaseLease,
    ) {}

    public function handle(
        RetailerConnection $connection,
        BrowserSessionPurpose $purpose,
        ?AutomationRun $run = null,
    ): BrowserSession {
        $leaseToken = $this->acquireLease->handle($connection);
        $reservationOwner = 'reservation:'.$leaseToken;

        try {
            $providerSession = $this->provider->createSession($connection->refresh(), $purpose);
            $session = BrowserSession::query()->create([
                'team_id' => $connection->team_id,
                'retailer_connection_id' => $connection->id,
                'automation_run_id' => $run?->id,
                'provider_session_id' => $providerSession->id,
                'purpose' => $purpose,
                'status' => in_array($purpose, [
                    BrowserSessionPurpose::Login,
                    BrowserSessionPurpose::Reauthentication,
                    BrowserSessionPurpose::ManualTakeover,
                ], true)
                    ? BrowserSessionStatus::HumanControl
                    : BrowserSessionStatus::AgentControl,
                'recording_enabled' => $providerSession->recordingEnabled,
                'started_at' => now(),
                'expires_at' => $providerSession->expiresAt,
                'metadata' => [
                    'region' => (string) config('services.browserbase.region', 'ap-southeast-1'),
                    'proxy_country' => (string) config('services.browserbase.proxy_country', 'AU'),
                    'protocol' => 'chef.browser.v1',
                ],
            ]);

            RetailerConnection::query()
                ->whereKey($connection->id)
                ->where('lease_owner', $reservationOwner)
                ->update([
                    'lease_owner' => 'session:'.$session->id,
                    'lease_expires_at' => $session->expires_at ?? now()->addSeconds((int) config('automation.lease_seconds', 900)),
                ]);

            return $session;
        } catch (Throwable $exception) {
            $this->releaseLease->handle($connection, $reservationOwner);

            throw $exception;
        }
    }
}
