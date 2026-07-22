<?php

use App\Enums\RetailerOrderRunItemStatus;

it('covers planned item outcomes including awaiting decision', function () {
    expect(RetailerOrderRunItemStatus::cases())->toHaveCount(8)
        ->and(collect(RetailerOrderRunItemStatus::cases())->map->value->all())->toBe([
            'pending',
            'searching',
            'matched',
            'substituted',
            'unavailable',
            'skipped',
            'awaiting_decision',
            'failed',
        ]);
});

it('treats matched substituted unavailable and skipped as resolved', function () {
    expect(RetailerOrderRunItemStatus::Matched->isResolved())->toBeTrue()
        ->and(RetailerOrderRunItemStatus::Substituted->isResolved())->toBeTrue()
        ->and(RetailerOrderRunItemStatus::Unavailable->isResolved())->toBeTrue()
        ->and(RetailerOrderRunItemStatus::Skipped->isResolved())->toBeTrue()
        ->and(RetailerOrderRunItemStatus::Pending->isResolved())->toBeFalse()
        ->and(RetailerOrderRunItemStatus::Searching->isResolved())->toBeFalse()
        ->and(RetailerOrderRunItemStatus::AwaitingDecision->isResolved())->toBeFalse()
        ->and(RetailerOrderRunItemStatus::Failed->isResolved())->toBeFalse();
});
