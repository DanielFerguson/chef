<?php

namespace App\Retailer\Data;

final readonly class CartInspection
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public array $lines = [],
        public ?float $total = null,
        public string $currency = 'AUD',
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
