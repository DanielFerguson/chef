<?php

namespace App\Automation\Data;

final readonly class ComputerUseTurn
{
    /**
     * @param  array<string, mixed>|null  $action
     * @param  array<int, array{id: string, code: string, message: string}>  $pendingSafetyChecks
     */
    public function __construct(
        public string $responseId,
        public ?string $callId,
        public ?array $action,
        public array $pendingSafetyChecks = [],
        public bool $complete = false,
        public ?string $message = null,
    ) {}
}
