<?php

namespace App\Automation\Data;

final readonly class CartInspection
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public array $lines,
        public ?float $total = null,
        public string $currency = 'AUD',
        public bool $botDetected = false,
        public bool $sensitiveScreen = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            lines: is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [],
            total: is_numeric($payload['total'] ?? null) ? (float) $payload['total'] : null,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : 'AUD',
            botDetected: (bool) ($payload['bot_detected'] ?? false),
            sensitiveScreen: (bool) ($payload['sensitive_screen'] ?? false),
        );
    }
}
