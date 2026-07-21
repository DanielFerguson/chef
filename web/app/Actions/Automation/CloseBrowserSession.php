<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Enums\BrowserSessionStatus;
use App\Models\BrowserSession;

class CloseBrowserSession
{
    public function __construct(
        private readonly BrowserSessionProvider $provider,
        private readonly ReleaseRetailerConnectionLease $releaseLease,
    ) {}

    public function handle(BrowserSession $session): void
    {
        if (in_array($session->status, [BrowserSessionStatus::Closed, BrowserSessionStatus::Expired], true)) {
            $this->releaseLease->handle($session->retailerConnection, 'session:'.$session->id);

            return;
        }

        $session->update(['status' => BrowserSessionStatus::Closing]);
        $this->provider->close($session);
        $session->update(['status' => BrowserSessionStatus::Closed, 'ended_at' => now()]);
        $this->releaseLease->handle($session->retailerConnection, 'session:'.$session->id);
    }
}
