<?php

namespace App\Actions\Retailer;

use App\Enums\ExistingCartDecision;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\RetailerOrderRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveCartDecision
{
    public function __construct(
        private readonly CancelRetailerOrderRun $cancelRetailerOrderRun,
    ) {}

    public function handle(RetailerOrderRun $run, User $user, string $choice): RetailerOrderRun
    {
        if (! $user->can('update', $run)) {
            throw new AuthorizationException('You cannot resolve this cart decision.');
        }

        $decision = ExistingCartDecision::tryFrom($choice);

        if ($decision === null) {
            throw ValidationException::withMessages([
                'choice' => 'Choose merge, replace, or cancel.',
            ]);
        }

        if ($decision === ExistingCartDecision::Cancel) {
            return $this->cancelRetailerOrderRun->handle($run, $user);
        }

        return DB::transaction(function () use ($run, $decision): RetailerOrderRun {
            $locked = RetailerOrderRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== RetailerOrderRunStatus::AwaitingCartDecision) {
                throw ValidationException::withMessages([
                    'retailer_order_run' => 'Cart decisions can only be made while Chef is waiting on the existing Woolworths cart.',
                ]);
            }

            $locked->update([
                'status' => RetailerOrderRunStatus::PreparingCart,
                'existing_cart_decision' => $decision,
                'failure_message' => null,
            ]);

            AdvanceRetailerOrderRunJob::dispatch($locked->id)
                ->onQueue((string) config('automation.queue', 'automation'));

            return $locked->refresh();
        });
    }
}
