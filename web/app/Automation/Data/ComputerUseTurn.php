<?php

namespace App\Automation\Data;

final readonly class ComputerUseTurn
{
    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, array{id: string, code: string, message: string}>  $pendingSafetyChecks
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public string $responseId,
        public ?string $callId,
        public array $actions,
        public array $pendingSafetyChecks = [],
        public bool $complete = false,
        public ?string $message = null,
        public array $diagnostics = [],
    ) {}
}
