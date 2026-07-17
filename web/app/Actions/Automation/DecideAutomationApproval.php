<?php

namespace App\Actions\Automation;

use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationApproval;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideAutomationApproval
{
    public function __construct(private readonly InvalidateAutomationRunWork $invalidateWork) {}

    public function handle(AutomationApproval $approval, User $user, bool $approved, ?string $note = null): AutomationApproval
    {
        if (! $user->can('update', $approval)) {
            throw new AuthorizationException('You cannot decide this automation approval.');
        }

        $approval = DB::transaction(function () use ($approval, $user, $approved, $note): AutomationApproval {
            $run = $approval->automationRun()->lockForUpdate()->firstOrFail();
            $approval = AutomationApproval::query()
                ->whereKey($approval->id)
                ->where('automation_run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($approval->status !== AutomationApprovalStatus::Pending) {
                return $approval;
            }

            if ($run->status !== AutomationRunStatus::AwaitingApproval) {
                throw ValidationException::withMessages(['approval' => 'This run is no longer waiting for this approval.']);
            }

            if ($approval->expires_at->isPast()) {
                $approval->update(['status' => AutomationApprovalStatus::Expired]);
                $this->invalidateWork->handle($run, 'An approval expired before this browser action was authorised.');
                $run->update([
                    'status' => AutomationRunStatus::Takeover,
                    'pause_reason' => 'An approval expired. Review the retailer tab before continuing.',
                    'takeover_at' => now(),
                ]);

                return $approval->refresh();
            }

            $approval->update([
                'status' => $approved ? AutomationApprovalStatus::Approved : AutomationApprovalStatus::Rejected,
                'decided_by_user_id' => $user->id,
                'decision_note' => filled($note) ? trim((string) $note) : null,
                'decided_at' => now(),
            ]);

            if (! $approved) {
                $this->invalidateWork->handle($run, 'The household rejected this action.');
                $run->update([
                    'status' => AutomationRunStatus::Takeover,
                    'pause_reason' => 'The proposed action was rejected. The retailer tab is ready for manual review.',
                    'takeover_at' => now(),
                ]);

                return $approval->refresh();
            }

            $pendingForStep = $approval->automation_step_id === null
                ? false
                : $run->approvals()->where('automation_step_id', $approval->automation_step_id)->where('status', AutomationApprovalStatus::Pending)->exists();

            $blockedForBoundary = $run->approvals()
                ->when(
                    $approval->automation_step_id === null,
                    fn ($query) => $query->whereNull('automation_step_id'),
                    fn ($query) => $query->where('automation_step_id', $approval->automation_step_id),
                )
                ->whereIn('status', [AutomationApprovalStatus::Rejected, AutomationApprovalStatus::Expired])
                ->exists();

            if ($blockedForBoundary) {
                $this->invalidateWork->handle($run, 'A related approval was rejected or expired before this action was authorised.');
                $run->update([
                    'status' => AutomationRunStatus::Takeover,
                    'pause_reason' => 'A related approval was rejected or expired. Review the retailer tab before continuing.',
                    'takeover_at' => now(),
                ]);

                return $approval->refresh();
            }

            if ($approval->automation_step_id !== null && ! $pendingForStep) {
                $approval->automationStep->update(['status' => AutomationStepStatus::Ready]);
                $run->update(['status' => AutomationRunStatus::Executing, 'pause_reason' => null]);
            } elseif ($approval->automation_step_id === null
                && ! $run->approvals()->whereNull('automation_step_id')->where('status', AutomationApprovalStatus::Pending)->exists()) {
                $run->update(['status' => AutomationRunStatus::AwaitingReview, 'pause_reason' => null]);
            }

            return $approval->refresh();
        });

        AutomationRunUpdated::dispatch($approval->automationRun->refresh());

        return $approval;
    }
}
