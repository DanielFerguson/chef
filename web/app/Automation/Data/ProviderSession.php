<?php

namespace App\Automation\Data;

use DateTimeImmutable;

final readonly class ProviderSession
{
    public function __construct(
        public string $id,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
