<?php

namespace App\Retailer\Data;

use Carbon\CarbonImmutable;

final readonly class FulfilmentOptions
{
    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    public function __construct(
        public string $type,
        public array $slots = [],
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'delivery';
        $slots = is_array($payload['slots'] ?? null) ? array_values($payload['slots']) : [];
        $expiresAt = null;

        if (is_string($payload['expires_at'] ?? null) && $payload['expires_at'] !== '') {
            $expiresAt = CarbonImmutable::parse($payload['expires_at']);
        }

        return new self(
            type: $type,
            slots: $slots,
            expiresAt: $expiresAt,
        );
    }
}
