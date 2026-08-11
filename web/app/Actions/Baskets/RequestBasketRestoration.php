<?php

namespace App\Actions\Baskets;

use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Jobs\RestoreBasketJob;
use App\Models\BasketRun;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestBasketRestoration
{
    public function handle(BasketRun $basketRun, User $user): BasketRun
    {
        if (! $user->can('restore', $basketRun)) {
            throw new AuthorizationException('Only the connected Coles account owner can restore this basket.');
        }
        $run = DB::transaction(function () use ($basketRun): BasketRun {
            $connection = $basketRun->retailer_connection_id === null
                ? null
                : RetailerConnection::query()->lockForUpdate()->find($basketRun->retailer_connection_id);
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);

            if (! $locked->snapshots()->where('kind', BasketSnapshotKind::Baseline)->exists()) {
                throw ValidationException::withMessages(['basket' => 'No previous basket snapshot is available.']);
            }

            if ($connection?->active_session_id !== null || $connection?->active_session_claim_token !== null) {
                throw ValidationException::withMessages([
                    'basket' => 'Close the Coles review session before restoring the basket.',
                ]);
            }

            if (! in_array($locked->status, [
                BasketRunStatus::Ready,
                BasketRunStatus::Failed,
                BasketRunStatus::Restored,
                BasketRunStatus::NeedsAttention,
            ], true)) {
                throw ValidationException::withMessages([
                    'basket' => 'Wait for current automation to stop before restoring the basket.',
                ]);
            }

            $locked->update([
                'status' => BasketRunStatus::Restoring,
                'restore_requested_at' => now(),
                'restore_completed_at' => null,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $locked;
        });
        RestoreBasketJob::dispatch($run->id);

        return $run->refresh();
    }
}
