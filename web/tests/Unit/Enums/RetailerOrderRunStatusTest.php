<?php

use App\Enums\RetailerOrderRunStatus;

it('exposes household-input awaiting statuses', function () {
    expect(RetailerOrderRunStatus::AwaitingFulfilmentSelection->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::AwaitingOrderConfirmation->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::AwaitingPlacementVerification->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::SubmittingOrder->requiresHouseholdInput())->toBeFalse()
        ->and(RetailerOrderRunStatus::Placed->isTerminal())->toBeTrue();
});
