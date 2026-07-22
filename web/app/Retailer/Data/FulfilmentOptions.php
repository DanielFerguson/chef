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
}
