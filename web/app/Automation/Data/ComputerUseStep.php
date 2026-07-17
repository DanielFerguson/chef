<?php

namespace App\Automation\Data;

final readonly class ComputerUseStep
{
    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<int, array<string, mixed>>  $safetyChecks
     * @param  array<int, array<string, mixed>>  $reconciliation
     */
    public function __construct(
        public string $responseId,
        public ?string $callId,
        public array $actions = [],
        public array $safetyChecks = [],
        public array $reconciliation = [],
        public ?string $message = null,
        public bool $complete = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            responseId: (string) $data['response_id'],
            callId: isset($data['call_id']) ? (string) $data['call_id'] : null,
            actions: array_values($data['actions'] ?? []),
            safetyChecks: array_values($data['safety_checks'] ?? []),
            reconciliation: array_values($data['reconciliation'] ?? []),
            message: isset($data['message']) ? (string) $data['message'] : null,
            complete: (bool) ($data['complete'] ?? false),
        );
    }
}
