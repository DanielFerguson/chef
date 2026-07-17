<?php

namespace App\Actions\Automation;

use App\Automation\RetailerOriginPolicy;
use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlAutomationRun
{
    public function __construct(private readonly RetailerOriginPolicy $origins) {}

    public function handle(AutomationRun $run, User $user, string $control): AutomationRun
    {
        if (! $user->can('update', $run)) {
            throw new AuthorizationException('You cannot control this automation run.');
        }

        if (! in_array($control, ['pause', 'resume', 'cancel', 'takeover', 'complete'], true)) {
            throw ValidationException::withMessages(['control' => 'Choose pause, resume, cancel, takeover, or complete.']);
        }

        $dispatch = false;
        $run = DB::transaction(function () use ($run, $control, &$dispatch): AutomationRun {
            $run = AutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status->terminal()) {
                throw ValidationException::withMessages(['automation_run' => 'This automation run has already finished.']);
            }

            if ($run->expires_at->isPast()) {
                $run->update(['status' => AutomationRunStatus::Expired, 'finished_at' => now()]);

                return $run->refresh();
            }

            match ($control) {
                'pause' => $this->pause($run),
                'resume' => $dispatch = $this->resume($run),
                'cancel' => $run->update(['status' => AutomationRunStatus::Cancelled, 'finished_at' => now(), 'pause_reason' => 'Cancelled by the household.']),
                'takeover' => $this->takeover($run),
                'complete' => $this->complete($run),
            };

            return $run->refresh();
        });

        AutomationRunUpdated::dispatch($run);

        if ($dispatch) {
            AdvanceAutomationRunJob::dispatch($run);
        }

        return $run;
    }

    private function pause(AutomationRun $run): void
    {
        if (! in_array($run->status, [AutomationRunStatus::Queued, AutomationRunStatus::Processing, AutomationRunStatus::Executing, AutomationRunStatus::AwaitingBrowser], true)) {
            throw ValidationException::withMessages(['automation_run' => 'This run cannot be paused in its current state.']);
        }

        $run->update(['status' => AutomationRunStatus::Paused, 'pause_reason' => 'Paused by the household.']);
    }

    private function resume(AutomationRun $run): bool
    {
        if (! in_array($run->status, [AutomationRunStatus::Paused, AutomationRunStatus::Takeover], true)) {
            throw ValidationException::withMessages(['automation_run' => 'This run is not paused or under manual control.']);
        }

        if ($run->status === AutomationRunStatus::Takeover) {
            $run->update([
                'status' => AutomationRunStatus::AwaitingBrowser,
                'previous_response_id' => null,
                'current_url' => null,
                'pause_reason' => 'Re-select the same retailer tab so Chef can inspect its current state before resuming.',
                'takeover_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            return false;
        }

        if ($run->current_url !== null) {
            $this->origins->assertAllowed($run->retailer, $run->current_url);

            if ($this->origins->requiresTakeover($run->current_url)) {
                throw ValidationException::withMessages(['automation_run' => 'Navigate back to the retailer cart before resuming Chef.']);
            }
        }

        if ($run->approvals()->where('status', AutomationApprovalStatus::Pending)->exists()) {
            $run->update(['status' => AutomationRunStatus::AwaitingApproval]);

            return false;
        }

        if ($run->steps()->where('status', AutomationStepStatus::Ready)->exists()) {
            $run->update(['status' => AutomationRunStatus::Executing, 'pause_reason' => null, 'takeover_at' => null]);

            return false;
        }

        if ($run->current_tab_id === null) {
            $run->update(['status' => AutomationRunStatus::AwaitingBrowser, 'pause_reason' => null, 'takeover_at' => null]);

            return false;
        }

        $run->update(['status' => AutomationRunStatus::Queued, 'pause_reason' => null, 'takeover_at' => null]);

        return true;
    }

    private function takeover(AutomationRun $run): void
    {
        $run->approvals()
            ->where('status', AutomationApprovalStatus::Pending)
            ->update(['status' => AutomationApprovalStatus::Expired]);
        $run->steps()
            ->whereIn('status', [AutomationStepStatus::Ready, AutomationStepStatus::AwaitingApproval, AutomationStepStatus::Executing])
            ->update([
                'status' => AutomationStepStatus::Failed,
                'error_message' => 'The household took manual control before this step completed.',
            ]);
        $run->update([
            'status' => AutomationRunStatus::Takeover,
            'takeover_at' => now(),
            'pause_reason' => 'The household took manual control of the selected retailer tab.',
        ]);
    }

    private function complete(AutomationRun $run): void
    {
        if ($run->status !== AutomationRunStatus::AwaitingReview) {
            throw ValidationException::withMessages(['automation_run' => 'Review the prepared cart before completing the handoff.']);
        }

        $run->update(['status' => AutomationRunStatus::Completed, 'finished_at' => now()]);
    }
}
