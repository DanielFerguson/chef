<?php

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use LogicException;

class TransitionAutomationRun
{
    /** @param array<string, mixed> $attributes */
    public function handle(AutomationRun $run, AutomationRunStatus $status, array $attributes = []): AutomationRun
    {
        $from = $run->status;

        if ($from !== $status && ! $this->canTransition($from, $status)) {
            throw new LogicException("Automation run cannot transition from {$from->value} to {$status->value}.");
        }

        if ($this->resumesActiveWork($from, $status)) {
            $attributes = [
                ...$attributes,
                'expires_at' => now()->addMinutes((int) config('automation.run_ttl_minutes', 60)),
            ];
        }

        $run->update([...$attributes, 'status' => $status]);

        return $run->refresh();
    }

    private function resumesActiveWork(AutomationRunStatus $from, AutomationRunStatus $to): bool
    {
        return in_array($from, [
            AutomationRunStatus::AwaitingReauthentication,
            AutomationRunStatus::AwaitingExistingCartDecision,
            AutomationRunStatus::AwaitingItemDecision,
        ], true) && in_array($to, [
            AutomationRunStatus::CheckingConnection,
            AutomationRunStatus::Queued,
        ], true);
    }

    public function canTransition(AutomationRunStatus $from, AutomationRunStatus $to): bool
    {
        if ($from->isTerminal()) {
            return false;
        }

        if (in_array($to, [AutomationRunStatus::Cancelled, AutomationRunStatus::Superseded, AutomationRunStatus::Expired], true)) {
            return true;
        }

        return in_array($to, match ($from) {
            AutomationRunStatus::CheckingConnection => [
                AutomationRunStatus::AwaitingReauthentication,
                AutomationRunStatus::InspectingExistingCart,
                AutomationRunStatus::AwaitingItemDecision,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::AwaitingReauthentication => [
                AutomationRunStatus::Queued,
                AutomationRunStatus::CheckingConnection,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::InspectingExistingCart => [
                AutomationRunStatus::AwaitingExistingCartDecision,
                AutomationRunStatus::Queued,
                AutomationRunStatus::AwaitingReauthentication,
                AutomationRunStatus::AwaitingItemDecision,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::AwaitingExistingCartDecision => [
                AutomationRunStatus::Queued,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::Queued => [
                AutomationRunStatus::CheckingConnection,
                AutomationRunStatus::Running,
                AutomationRunStatus::AwaitingReauthentication,
                AutomationRunStatus::AwaitingItemDecision,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::Running => [
                AutomationRunStatus::AwaitingReauthentication,
                AutomationRunStatus::AwaitingItemDecision,
                AutomationRunStatus::Reconciling,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::AwaitingItemDecision => [
                AutomationRunStatus::Queued,
                AutomationRunStatus::Failed,
            ],
            AutomationRunStatus::Reconciling => [
                AutomationRunStatus::ReadyForReview,
                AutomationRunStatus::AwaitingReauthentication,
                AutomationRunStatus::AwaitingItemDecision,
                AutomationRunStatus::Failed,
            ],
            default => [],
        }, true);
    }
}
