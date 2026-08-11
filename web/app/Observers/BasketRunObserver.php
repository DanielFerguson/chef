<?php

namespace App\Observers;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Models\BasketRunStatusTransition;
use App\Models\User;
use App\Notifications\BasketRunStatusNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class BasketRunObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(BasketRun $basketRun): void
    {
        if (! $basketRun->wasChanged('status')) {
            return;
        }

        $fromStatus = (string) $basketRun->getRawOriginal('status');
        $toStatus = $basketRun->status->value;
        $lastTransition = $basketRun->statusTransitions()->latest('transitioned_at')->first();
        $anchor = $lastTransition instanceof BasketRunStatusTransition
            ? $lastTransition->transitioned_at
            : $basketRun->created_at;
        $reasonCode = $basketRun->failure_code ?? $basketRun->attention_kind;
        if (! is_string($reasonCode)
            || preg_match('/^[a-z0-9_.:-]{1,80}$/', $reasonCode) !== 1) {
            $reasonCode = null;
        }
        if ($fromStatus !== '' && $fromStatus !== $toStatus) {
            $transition = $basketRun->statusTransitions()->create([
                'team_id' => $basketRun->team_id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason_code' => $reasonCode,
                'duration_ms' => max(0, (int) $anchor->diffInMilliseconds(now())),
                'transitioned_at' => now(),
            ]);

            Log::info('Retailer basket run transitioned.', [
                'from_status' => $transition->from_status,
                'to_status' => $transition->to_status,
                'reason_code' => $transition->reason_code,
                'duration_ms' => $transition->duration_ms,
            ]);
        }

        if (! in_array($basketRun->status, [
            BasketRunStatus::Ready,
            BasketRunStatus::NeedsProduct,
            BasketRunStatus::NeedsPlanReview,
            BasketRunStatus::ReauthenticationRequired,
            BasketRunStatus::Failed,
            BasketRunStatus::Uncertain,
            BasketRunStatus::NeedsAttention,
        ], true)) {
            return;
        }

        $basketRun->loadMissing('connection:id,owner_user_id');
        $recipientIds = collect([
            $basketRun->requested_by_user_id,
            $basketRun->connection?->owner_user_id,
        ])->filter()->unique();

        User::query()
            ->whereKey($recipientIds)
            ->whereHas('memberships', fn ($query) => $query->where('team_id', $basketRun->team_id))
            ->each(fn (User $user) => $user->notify(new BasketRunStatusNotification($basketRun)));
    }
}
