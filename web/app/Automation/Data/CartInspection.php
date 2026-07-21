<?php

namespace App\Automation\Data;

final readonly class CartInspection
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $lines,
        public ?float $total = null,
        public string $currency = 'AUD',
        public bool $botDetected = false,
        public bool $sensitiveScreen = false,
        public array $diagnostics = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diagnostics
     */
    public static function fromPayload(array $payload, array $diagnostics = []): self
    {
        return new self(
            lines: is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [],
            total: is_numeric($payload['total'] ?? null) ? (float) $payload['total'] : null,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : 'AUD',
            botDetected: (bool) ($payload['bot_detected'] ?? false),
            sensitiveScreen: (bool) ($payload['sensitive_screen'] ?? false),
            diagnostics: $diagnostics,
        );
    }
}
