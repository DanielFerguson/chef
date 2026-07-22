<?php

namespace App\Actions\Retailer;

use App\Actions\Automation\CloseBrowserSession;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerOrderRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelRetailerOrderRun
{
    public function __construct(
        private readonly CloseBrowserSession $closeBrowserSession,
    ) {}

    public function handle(RetailerOrderRun $run, User $user): RetailerOrderRun
    {
        if (! $user->can('cancel', $run)) {
            throw new AuthorizationException('You cannot cancel this Woolworths order run.');
        }

        try {
            return Cache::lock('chef-retailer-order-run:'.$run->id, (int) config('automation.lease_seconds', 900))
                ->block(5, fn (): RetailerOrderRun => $this->cancel($run));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Chef is finishing a verified browser step. Try cancellation again in a moment.',
            ]);
        }
    }

    private function cancel(RetailerOrderRun $run): RetailerOrderRun
    {
        $run = $run->refresh();

        if ($run->status->isTerminal()) {
            return $run;
        }

        $sessions = $run->retailerConnection
            ->browserSessions()
            ->whereNotIn('status', [
                BrowserSessionStatus::Closed->value,
                BrowserSessionStatus::Expired->value,
            ])
            ->get();

        foreach ($sessions as $session) {
            $this->closeBrowserSession->handle($session);
        }

        return DB::transaction(function () use ($run): RetailerOrderRun {
            $locked = RetailerOrderRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $locked->update([
                'status' => RetailerOrderRunStatus::Cancelled,
                'failure_message' => null,
            ]);

            return $locked->refresh();
        });
    }
}
