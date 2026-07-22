<?php

namespace App\Actions\Automation;

use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\RetailerCartAdapter;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\BrowserSession;
use App\Models\RetailerOrderRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VerifyRetailerConnection
{
    public function __construct(
        private readonly RetailerCartAdapter $adapter,
        private readonly ComputerExecutor $executor,
        private readonly ReleaseRetailerConnectionLease $releaseLease,
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
            $this->executor->resumeControl($session);
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
            $this->yieldBackToHuman($session);
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
            $this->yieldBackToHuman($session);
            $connection->update(['status' => RetailerConnectionStatus::ReauthenticationRequired]);

            throw ValidationException::withMessages([
                'connection' => $check->reason,
            ]);
        }

        $orderRun = $connection->orderRuns()
            ->where('status', RetailerOrderRunStatus::AwaitingReauthentication->value)
            ->oldest('id')
            ->first();

        if ($orderRun === null) {
            $session->update([
                'purpose' => BrowserSessionPurpose::CartPreparation,
                'status' => BrowserSessionStatus::AgentControl,
                'metadata' => [
                    ...($session->metadata ?? []),
                    'verified_for_cart_preparation_at' => now()->toIso8601String(),
                ],
            ]);
        } else {
            $session->update([
                'purpose' => BrowserSessionPurpose::CartPreparation,
                'status' => BrowserSessionStatus::AgentControl,
                'metadata' => [
                    ...($session->metadata ?? []),
                    'resumed_after_reauthentication' => true,
                    'retailer_order_run_id' => $orderRun->id,
                ],
            ]);
        }

        $connection->update([
            'status' => RetailerConnectionStatus::Connected,
            'last_verified_at' => now(),
            'disconnected_at' => null,
        ]);

        if ($orderRun !== null) {
            $this->resumeOrderRun($orderRun);
        }
    }

    private function resumeOrderRun(RetailerOrderRun $run): void
    {
        $run->update([
            'status' => RetailerOrderRunStatus::PreparingCart,
            'failure_message' => null,
        ]);

        AdvanceRetailerOrderRunJob::dispatch($run->id)
            ->onQueue((string) config('automation.queue', 'automation'));
    }

    private function yieldBackToHuman(BrowserSession $session): void
    {
        try {
            $this->executor->yieldControl($session);
        } catch (\Throwable) {
            // The same bounded provider session remains available until its
            // expiry even if the local actor cannot acknowledge the yield.
        }
    }
}
