<?php

namespace App\Retailer\Data;

final readonly class AuthCheck
{
    public function __construct(
        public bool $authenticated,
        public string $reason = '',
        public bool $botDetected = false,
        public bool $sensitiveScreen = false,
    ) {}
}
