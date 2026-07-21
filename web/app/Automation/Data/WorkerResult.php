<?php

namespace App\Automation\Data;

final readonly class WorkerResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public bool $ok,
        public array $payload = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
