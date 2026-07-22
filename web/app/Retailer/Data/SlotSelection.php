<?php

namespace App\Retailer\Data;

final readonly class SlotSelection
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
        public ?float $fee = null,
        public string $fulfilmentType = 'delivery',
    ) {}
}
