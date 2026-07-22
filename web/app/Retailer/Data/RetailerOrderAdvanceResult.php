<?php

namespace App\Retailer\Data;

final readonly class RetailerOrderAdvanceResult
{
    public function __construct(
        public bool $shouldContinue,
        public string $checkpoint,
    ) {}
}
