<?php

namespace App\Actions\Retailer;

use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\RetailerOrderRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ConfirmRetailerOrder
{
    public function handle(RetailerOrderRun $run, User $user): RetailerOrderRun
    {
        if (! $user->can('confirm', $run)) {
            throw new AuthorizationException('You cannot confirm this Woolworths order.');
        }

        $run = $run->fresh(['retailerConnection']);

        if ($run === null || $run->status !== RetailerOrderRunStatus::AwaitingOrderConfirmation) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Confirm the order only after a fulfilment slot is selected.',
            ]);
        }

        if ($run->cart_checksum === null || $run->fulfilment_type === null || $run->selected_slot === null) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Cart checksum, fulfilment type, and selected slot are required before confirm.',
            ]);
        }

        $fingerprint = self::fingerprint($run);

        $run->update([
            'status' => RetailerOrderRunStatus::SubmittingOrder,
            'confirmation_fingerprint' => $fingerprint,
            'confirmation' => [
                'user_id' => $user->id,
                'confirmed_at' => now()->toIso8601String(),
                'fingerprint' => $fingerprint,
            ],
            'failure_message' => null,
        ]);

        AdvanceRetailerOrderRunJob::dispatch($run->id)
            ->onQueue((string) config('automation.queue', 'automation'));

        return $run->refresh();
    }

    /**
     * @return array{
     *     fulfilment_type: string|null,
     *     selected_slot: array<string, mixed>|null,
     *     fingerprint: string,
     *     consequence: string,
     * }
     */
    public static function confirmationPayload(RetailerOrderRun $run): array
    {
        return [
            'fulfilment_type' => $run->fulfilment_type,
            'selected_slot' => $run->selected_slot,
            'fingerprint' => self::fingerprint($run),
            'consequence' => 'Chef will place the Woolworths order using the default card on file. You can cancel before confirming.',
        ];
    }

    public static function fingerprint(RetailerOrderRun $run): string
    {
        return hash('sha256', json_encode([
            'cart_checksum' => $run->cart_checksum,
            'fulfilment_type' => $run->fulfilment_type,
            'selected_slot' => $run->selected_slot,
        ], JSON_THROW_ON_ERROR));
    }
}
