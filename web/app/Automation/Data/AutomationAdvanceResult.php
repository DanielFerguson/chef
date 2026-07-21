<?php

namespace App\Automation\Data;

final readonly class AutomationAdvanceResult
{
    public function __construct(
        public bool $shouldContinue,
        public string $checkpoint,
    ) {}
}
