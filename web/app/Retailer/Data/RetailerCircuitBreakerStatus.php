<?php

namespace App\Retailer\Data;

use Carbon\CarbonInterface;

readonly class RetailerCircuitBreakerStatus
{
    public function __construct(
        public string $state,
        public string $reason,
        public ?CarbonInterface $changedAt,
        public bool $available,
    ) {}

    /** @return array{state: string, reason: string, changed_at: ?string, available: bool} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'changed_at' => $this->changedAt?->toIso8601String(),
            'available' => $this->available,
        ];
    }
}
