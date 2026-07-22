<?php

namespace App\Actions\Retailer;

use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerOrderRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class VerifyRetailerPlacement
{
    public function __construct(
        private readonly RecordPlacedRetailerOrder $recordPlacedRetailerOrder,
    ) {}

    public function handle(
        RetailerOrderRun $run,
        User $user,
        ?string $retailerOrderReference = null,
        bool $acknowledgedPlaced = false,
    ): RetailerOrderRun {
        if (! $user->can('verifyPlacement', $run)) {
            throw new AuthorizationException('You cannot verify this Woolworths order placement.');
        }

        $run = $run->fresh();

        if ($run === null || $run->status !== RetailerOrderRunStatus::AwaitingPlacementVerification) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Placement can only be verified while Chef is waiting for confirmation.',
            ]);
        }

        $reference = filled($retailerOrderReference) ? trim((string) $retailerOrderReference) : null;

        if ($reference === null && ! $acknowledgedPlaced) {
            throw ValidationException::withMessages([
                'retailer_order_reference' => 'Enter the Woolworths order number or confirm that the order was placed.',
            ]);
        }

        $this->recordPlacedRetailerOrder->handle(
            $run,
            $user,
            retailerOrderReference: $reference,
            confirmationText: $acknowledgedPlaced && $reference === null
                ? 'Household confirmed the Woolworths order was placed.'
                : null,
        );

        return $run->refresh();
    }
}
