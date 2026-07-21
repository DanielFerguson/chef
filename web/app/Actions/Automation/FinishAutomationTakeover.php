<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\RetailerCartAdapter;
use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\BrowserSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinishAutomationTakeover
{
    public function __construct(
        private readonly RetailerCartAdapter $adapter,
        private readonly TransitionAutomationRun $transition,
    ) {}

    public function handle(BrowserSession $session, User $user): void
    {
        if (! $user->can('control', $session)) {
            throw new AuthorizationException('Only the connection owner can finish manual takeover.');
        }

        if ($session->purpose !== BrowserSessionPurpose::ManualTakeover
            || $session->status !== BrowserSessionStatus::HumanControl
            || $session->run === null) {
            throw ValidationException::withMessages(['automation' => 'This manual takeover session is no longer active.']);
        }

        $run = $session->run;

        try {
            Cache::lock('chef-automation-run:'.$run->id, (int) config('automation.lease_seconds', 900))
                ->block(5, function () use ($session, $run, $user): void {
                    $session->update(['status' => BrowserSessionStatus::AgentControl]);

                    try {
                        $check = $this->adapter->checkAuthentication($session);
                    } catch (\Throwable $exception) {
                        $session->update(['status' => BrowserSessionStatus::HumanControl]);

                        throw $exception;
                    }

                    if (! $check->authenticated || $check->botDetected || $check->sensitiveScreen) {
                        $session->update(['status' => BrowserSessionStatus::HumanControl]);

                        throw ValidationException::withMessages([
                            'automation' => $check->reason,
                        ]);
                    }

                    $session->update([
                        'purpose' => BrowserSessionPurpose::CartPreparation,
                        'status' => BrowserSessionStatus::AgentControl,
                        'metadata' => [
                            ...($session->metadata ?? []),
                            'resumed_after_manual_takeover' => true,
                        ],
                    ]);
                    $run->retailerConnection->update([
                        'status' => RetailerConnectionStatus::Connected,
                        'last_verified_at' => now(),
                    ]);

                    DB::transaction(function () use ($run, $user): void {
                        $intervention = $run->interventions()
                            ->where('type', AutomationInterventionType::ManualTakeover->value)
                            ->where('status', AutomationInterventionStatus::Pending->value)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $intervention->update([
                            'status' => AutomationInterventionStatus::Resolved,
                            'resolution' => ['choice' => 'resume_after_takeover'],
                            'resolved_by_user_id' => $user->id,
                            'resolved_at' => now(),
                        ]);
                        $this->transition->handle($run->refresh(), AutomationRunStatus::Queued);
                    });
                });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'automation' => 'Chef is finishing another verified browser step. Try again in a moment.',
            ]);
        }

        AdvanceAutomationRunJob::dispatch($run->id)
            ->onQueue((string) config('automation.queue', 'automation'));
    }
}
