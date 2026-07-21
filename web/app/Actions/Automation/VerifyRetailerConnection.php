<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\RetailerCartAdapter;
use App\Enums\AutomationInterventionStatus;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\BrowserSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class VerifyRetailerConnection
{
    public function __construct(
        private readonly RetailerCartAdapter $adapter,
        private readonly CloseBrowserSession $closeSession,
        private readonly TransitionAutomationRun $transition,
    ) {}

    public function handle(BrowserSession $session, User $user): void
    {
        if (! $user->can('control', $session)) {
            throw new AuthorizationException('Only the connection owner can verify this login.');
        }

        if ($session->status !== BrowserSessionStatus::HumanControl) {
            throw ValidationException::withMessages(['connection' => 'This login session is no longer available.']);
        }

        $connection = $session->retailerConnection;
        $connection->update(['status' => RetailerConnectionStatus::Checking]);
        $check = $this->adapter->checkAuthentication($session);

        if ($check->botDetected || $check->sensitiveScreen || ! $check->authenticated) {
            $connection->update(['status' => RetailerConnectionStatus::ReauthenticationRequired]);

            throw ValidationException::withMessages([
                'connection' => $check->reason,
            ]);
        }

        $this->closeSession->handle($session);
        $connection->update([
            'status' => RetailerConnectionStatus::Connected,
            'last_verified_at' => now(),
            'disconnected_at' => null,
        ]);

        $connection->runs()
            ->where('status', AutomationRunStatus::AwaitingReauthentication->value)
            ->get()
            ->each(function ($run) use ($user): void {
                $run->interventions()
                    ->where('type', AutomationInterventionType::Reauthentication->value)
                    ->where('status', AutomationInterventionStatus::Pending->value)
                    ->update([
                        'status' => AutomationInterventionStatus::Resolved->value,
                        'resolution' => json_encode(['choice' => 'authentication_verified'], JSON_THROW_ON_ERROR),
                        'resolved_by_user_id' => $user->id,
                        'resolved_at' => now(),
                    ]);
                $this->transition->handle($run, AutomationRunStatus::Queued);
                AdvanceAutomationRunJob::dispatch($run->id)
                    ->onQueue((string) config('automation.queue', 'automation'));
            });
    }
}
