<?php

namespace App\Automation\Data;

use App\Enums\AutomationPolicyDecision;

final readonly class PolicyAssessment
{
    public function __construct(
        public AutomationPolicyDecision $decision,
        public string $reason,
    ) {}
}
