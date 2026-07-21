<?php

namespace App\Actions\Automation;

use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAutomationRun
{
    public function __construct(
        private readonly TransitionAutomationRun $transition,
        private readonly CloseBrowserSession $closeSession,
    ) {}

    public function handle(AutomationRun $run, User $user): AutomationRun
    {
        if (! $user->can('cancel', $run)) {
            throw new AuthorizationException('You cannot cancel this cart run.');
        }

        try {
            return Cache::lock('chef-automation-run:'.$run->id, (int) config('automation.lease_seconds', 900))
                ->block(5, fn (): AutomationRun => $this->cancel($run));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'automation' => 'Chef is finishing a verified browser step. Try cancellation again in a moment.',
            ]);
        }
    }

    private function cancel(AutomationRun $run): AutomationRun
    {
        $run = $run->refresh();

        if ($run->status->isTerminal()) {
            return $run;
        }

        foreach ($run->browserSessions()->whereNotIn('status', ['closed', 'expired'])->get() as $session) {
            $this->closeSession->handle($session);
        }

        return DB::transaction(function () use ($run): AutomationRun {
            $run->interventions()->where('status', AutomationInterventionStatus::Pending->value)->update([
                'status' => AutomationInterventionStatus::Cancelled->value,
                'resolved_at' => now(),
            ]);

            return $this->transition->handle($run->refresh(), AutomationRunStatus::Cancelled, [
                'finished_at' => now(),
            ]);
        });
    }
}
