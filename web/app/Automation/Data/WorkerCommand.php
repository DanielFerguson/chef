<?php

namespace App\Automation\Data;

final readonly class WorkerCommand
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload = [],
    ) {}

    /** @return array{version: string, type: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'version' => 'chef.browser.v1',
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}
