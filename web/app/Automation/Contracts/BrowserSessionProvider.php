<?php

namespace App\Automation\Contracts;

use App\Automation\Data\ProviderSession;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;

interface BrowserSessionProvider
{
    public function createContext(): string;

    public function createSession(RetailerConnection $connection, BrowserSessionPurpose $purpose): ProviderSession;

    /** Returns a transient CDP URL. Callers must never persist or log it. */
    public function connectionUrl(BrowserSession $session): string;

    /** Returns a transient Live View URL. Callers must never persist or log it. */
    public function liveViewUrl(BrowserSession $session): string;

    public function close(BrowserSession $session): void;

    public function deleteContext(RetailerConnection $connection): void;
}
