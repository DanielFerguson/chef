<?php

namespace App\Actions\Automation;

use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationStepStatus;
use App\Models\AutomationRun;

class InvalidateAutomationRunWork
{
    public function handle(AutomationRun $run, string $message): void
    {
        $run->approvals()
            ->where('status', AutomationApprovalStatus::Pending)
            ->update(['status' => AutomationApprovalStatus::Expired]);
        $run->steps()
            ->whereIn('status', [AutomationStepStatus::Ready, AutomationStepStatus::AwaitingApproval, AutomationStepStatus::Executing])
            ->update([
                'status' => AutomationStepStatus::Failed,
                'error_message' => $message,
            ]);
    }
}
