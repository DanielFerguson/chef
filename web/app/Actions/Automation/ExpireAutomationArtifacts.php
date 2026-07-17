<?php

namespace App\Actions\Automation;

use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationApproval;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpireAutomationArtifacts
{
    /** @return array{runs: int, approvals: int, screenshots: int, connections: int, stalled_steps: int} */
    public function handle(): array
    {
        $stalledSteps = 0;
        AutomationStep::query()
            ->where('status', AutomationStepStatus::Executing)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->each(function (AutomationStep $step) use (&$stalledSteps): void {
                $run = DB::transaction(function () use ($step): ?AutomationRun {
                    $step = AutomationStep::query()->whereKey($step->id)->lockForUpdate()->first();

                    if ($step === null || $step->status !== AutomationStepStatus::Executing) {
                        return null;
                    }

                    $run = $step->automationRun()->lockForUpdate()->firstOrFail();
                    $step->update([
                        'status' => AutomationStepStatus::Failed,
                        'error_message' => 'The browser extension did not return this action batch within five minutes.',
                    ]);

                    if ($run->status === AutomationRunStatus::Executing) {
                        $run->update([
                            'status' => AutomationRunStatus::Takeover,
                            'error_code' => 'extension_result_timeout',
                            'error_message' => 'Chef stopped because the browser extension did not return an action result.',
                            'pause_reason' => 'Check the selected retailer tab before deciding whether to resume.',
                            'takeover_at' => now(),
                        ]);
                    }

                    return $run->refresh();
                });

                if ($run !== null) {
                    $stalledSteps++;
                    AutomationRunUpdated::dispatch($run);
                }
            });

        $runs = AutomationRun::query()
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
            ->update(['status' => AutomationRunStatus::Expired, 'finished_at' => now()]);
        $approvals = AutomationApproval::query()
            ->where('expires_at', '<=', now())
            ->where('status', AutomationApprovalStatus::Pending)
            ->update(['status' => AutomationApprovalStatus::Expired]);
        $screenshots = 0;

        AutomationStep::query()
            ->whereNotNull('screenshot_path')
            ->where('screenshot_expires_at', '<=', now())
            ->each(function (AutomationStep $step) use (&$screenshots): void {
                Storage::disk('local')->delete((string) $step->screenshot_path);
                $step->update(['screenshot_path' => null, 'screenshot_expires_at' => null]);
                $screenshots++;
            });

        $connections = BrowserConnection::query()
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['pending', 'active'])
            ->update(['status' => 'expired', 'pairing_code_hash' => null, 'token_hash' => null]);

        return [
            'runs' => $runs,
            'approvals' => $approvals,
            'screenshots' => $screenshots,
            'connections' => $connections,
            'stalled_steps' => $stalledSteps,
        ];
    }
}
