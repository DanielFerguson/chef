<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\RetailerCartAdapter;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Models\AutomationRun;
use App\Models\BrowserSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartAutomationTakeover
{
    public function __construct(
        private readonly CreateBrowserSession $createSession,
        private readonly CloseBrowserSession $closeSession,
        private readonly CreateAutomationIntervention $createIntervention,
        private readonly TransitionAutomationRun $transition,
        private readonly RetailerCartAdapter $adapter,
    ) {}

    public function handle(AutomationRun $run, User $user): BrowserSession
    {
        if (! $user->can('useForAutomation', $run->retailerConnection)) {
            throw new AuthorizationException('Only the Woolworths connection owner can take over this browser.');
        }

        try {
            return Cache::lock('chef-automation-run:'.$run->id, (int) config('automation.lease_seconds', 900))
                ->block(5, function () use ($run, $user): BrowserSession {
                    $run = $run->refresh();

                    if (! in_array($run->status, [
                        AutomationRunStatus::CheckingConnection,
                        AutomationRunStatus::InspectingExistingCart,
                        AutomationRunStatus::Queued,
                        AutomationRunStatus::Running,
                        AutomationRunStatus::Reconciling,
                    ], true)) {
                        throw ValidationException::withMessages([
                            'automation' => 'This cart run is not currently available for manual takeover.',
                        ]);
                    }

                    $session = $run->browserSessions()
                        ->where('status', BrowserSessionStatus::AgentControl->value)
                        ->latest()
                        ->first();
                    $created = $session === null;
                    $previousPurpose = $session?->purpose;

                    if ($session?->recording_enabled) {
                        $this->closeSession->handle($session);
                        $session = $this->createSession->handle(
                            $run->retailerConnection,
                            BrowserSessionPurpose::ManualTakeover,
                            $run,
                        );
                        $session->update([
                            'metadata' => [
                                ...($session->metadata ?? []),
                                'previous_purpose' => $previousPurpose?->value,
                            ],
                        ]);
                        $created = true;
                    } elseif ($session === null) {
                        $session = $this->createSession->handle(
                            $run->retailerConnection,
                            BrowserSessionPurpose::ManualTakeover,
                            $run,
                        );
                    } else {
                        $session->update([
                            'purpose' => BrowserSessionPurpose::ManualTakeover,
                            'status' => BrowserSessionStatus::HumanControl,
                            'metadata' => [
                                ...($session->metadata ?? []),
                                'previous_purpose' => $previousPurpose?->value,
                            ],
                        ]);
                    }

                    try {
                        $this->adapter->openCart($session);
                    } catch (Throwable $exception) {
                        if ($created) {
                            $this->closeSession->handle($session);
                        } else {
                            $session->update([
                                'purpose' => $previousPurpose ?? BrowserSessionPurpose::CartPreparation,
                                'status' => BrowserSessionStatus::AgentControl,
                            ]);
                        }

                        throw $exception;
                    }

                    $this->createIntervention->handle(
                        $run,
                        AutomationInterventionType::ManualTakeover,
                        ['message' => 'The connection owner paused Chef and took control of the Woolworths cart.'],
                        session: $session,
                        requester: $user,
                    );
                    $this->transition->handle($run, AutomationRunStatus::AwaitingItemDecision);

                    return $session->refresh();
                });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'automation' => 'Chef is finishing a verified browser step. Try takeover again in a moment.',
            ]);
        }
    }
}
