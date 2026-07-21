<?php

namespace App\Automation\Testing;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Data\ProviderSession;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use DateTimeImmutable;

class FakeBrowserSessionProvider implements BrowserSessionProvider
{
    public ?\Throwable $createContextFailure = null;

    public ?\Throwable $createSessionFailure = null;

    public int $contextsCreated = 0;

    public int $contextsDeleted = 0;

    public int $sessionsCreated = 0;

    public int $sessionsClosed = 0;

    public bool $loseLiveView = false;

    public bool $recordingEnabled = false;

    public function createContext(): string
    {
        if ($this->createContextFailure !== null) {
            throw $this->createContextFailure;
        }

        $this->contextsCreated++;

        return 'fake-context-'.$this->contextsCreated;
    }

    public function createSession(RetailerConnection $connection, BrowserSessionPurpose $purpose): ProviderSession
    {
        if ($this->createSessionFailure !== null) {
            $failure = $this->createSessionFailure;
            $this->createSessionFailure = null;

            throw $failure;
        }

        $this->sessionsCreated++;

        return new ProviderSession(
            'fake-session-'.$this->sessionsCreated,
            new DateTimeImmutable('+15 minutes'),
            $purpose === BrowserSessionPurpose::CartPreparation && $this->recordingEnabled,
        );
    }

    public function connectionUrl(BrowserSession $session): string
    {
        return 'wss://browserbase.invalid/devtools/'.$session->id;
    }

    public function liveViewUrl(BrowserSession $session): string
    {
        if ($this->loseLiveView) {
            throw new BrowserSessionLostException;
        }

        return 'https://browserbase.invalid/live/'.$session->id;
    }

    public function close(BrowserSession $session): void
    {
        $this->sessionsClosed++;
    }

    public function deleteContext(RetailerConnection $connection): void
    {
        $this->contextsDeleted++;
    }
}
