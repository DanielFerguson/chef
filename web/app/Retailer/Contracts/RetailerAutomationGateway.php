<?php

namespace App\Retailer\Contracts;

use App\Enums\RetailerWorkerCommand;
use App\Retailer\Data\RetailerLiveSession;
use App\Retailer\Data\RetailerWorkerResult;

interface RetailerAutomationGateway
{
    public function createContext(): string;

    public function deleteContext(string $contextId): void;

    public function startLiveSession(string $contextId, string $purpose): RetailerLiveSession;

    public function relayLiveInput(
        string $contextId,
        string $sessionId,
        #[\SensitiveParameter] ?string $text,
        ?string $key,
    ): RetailerWorkerResult;

    /** @param array<string, mixed> $payload */
    public function execute(
        string $contextId,
        RetailerWorkerCommand $command,
        array $payload = [],
        ?string $sessionId = null,
    ): RetailerWorkerResult;
}
