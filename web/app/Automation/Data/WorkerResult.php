<?php

namespace App\Automation\Data;

final readonly class WorkerResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public bool $ok,
        public array $payload = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $diagnostics = [],
    ) {}
}
