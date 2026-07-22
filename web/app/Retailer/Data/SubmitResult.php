<?php

namespace App\Retailer\Data;

final readonly class SubmitResult
{
    public function __construct(
        public bool $ok,
        public ?string $retailerOrderReference = null,
        public ?string $confirmationText = null,
        public ?string $errorMessage = null,
    ) {}
}
