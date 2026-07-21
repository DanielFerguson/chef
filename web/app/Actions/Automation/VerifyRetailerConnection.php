<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Exceptions\BrowserSessionLostException;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VerifyRetailerConnection
{
    public function __construct(
        private readonly RetailerCartAdapter $adapter,
        private readonly CloseBrowserSession $closeSession,
        private readonly ReleaseRetailerConnectionLease $releaseLease,
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

        try {
            $check = $this->adapter->checkAuthentication($session);
        } catch (BrowserSessionLostException) {
            $session->update([
                'status' => BrowserSessionStatus::Expired,
                'ended_at' => now(),
            ]);
            $this->releaseLease->handle($connection, 'session:'.$session->id);
            $connection->update(['status' => RetailerConnectionStatus::ReauthenticationRequired]);

            throw ValidationException::withMessages([
                'connection' => 'The secure Woolworths browser ended before Chef could verify it. Return to Shopping and reconnect.',
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('Retailer authentication verification did not reach a safe result.', [
                'browser_session_id' => $session->id,
                'retailer_connection_id' => $connection->id,
                'failure_type' => $exception::class,
            ]);
            $connection->update(['status' => RetailerConnectionStatus::PendingLogin]);

            throw ValidationException::withMessages([
                'connection' => 'Chef could not verify the protected Woolworths cart yet. Your secure session is still open; wait a moment and try again.',
            ]);
        }

        if ($check->botDetected || $check->sensitiveScreen || ! $check->authenticated) {
            $connection->update(['status' => RetailerConnectionStatus::ReauthenticationRequired]);

            throw ValidationException::withMessages([
                'connection' => $check->reason,
            ]);
        }

        $run = $connection->runs()
            ->where('status', AutomationRunStatus::AwaitingReauthentication->value)
            ->oldest('id')
            ->first();

        if ($run === null) {
            $this->closeSession->handle($session);
            $syncDelay = max(0, (int) config('automation.context_sync_delay_milliseconds', 3000));
            if ($syncDelay > 0) {
                usleep($syncDelay * 1000);
            }
        } else {
            $session->update([
                'automation_run_id' => $run->id,
                'purpose' => BrowserSessionPurpose::CartPreparation,
                'status' => BrowserSessionStatus::AgentControl,
                'metadata' => [
                    ...($session->metadata ?? []),
                    'resumed_after_reauthentication' => true,
                ],
            ]);
        }

        $connection->update([
            'status' => RetailerConnectionStatus::Connected,
            'last_verified_at' => now(),
            'disconnected_at' => null,
        ]);

        if ($run !== null) {
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
        }
    }
}
