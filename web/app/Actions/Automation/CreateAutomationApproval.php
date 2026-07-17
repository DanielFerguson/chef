<?php

namespace App\Actions\Automation;

use App\Enums\AutomationApprovalStatus;
use App\Models\AutomationApproval;
use App\Models\AutomationRun;
use App\Models\AutomationStep;

class CreateAutomationApproval
{
    public function handle(
        AutomationRun $run,
        string $riskKind,
        string $proposedAction,
        string $consequence,
        ?AutomationStep $step = null,
    ): AutomationApproval {
        return $run->approvals()->create([
            'team_id' => $run->team_id,
            'automation_step_id' => $step?->id,
            'risk_kind' => $riskKind,
            'proposed_action' => $proposedAction,
            'consequence' => $consequence,
            'status' => AutomationApprovalStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
