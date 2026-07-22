<?php

use App\Actions\Retailer\AdvanceRetailerOrderRun;
use App\Actions\Retailer\ConfirmRetailerOrder;
use App\Actions\Retailer\VerifyRetailerPlacement;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\MealPlan;
use App\Models\Order;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\ShoppingList;
use App\Models\User;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\SubmitResult;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     owner: User,
 *     member: User,
 *     outsider: User,
 *     run: RetailerOrderRun,
 *     browser: FakeRetailerBrowser,
 *     slot: array<string, mixed>,
 *     cartChecksum: string,
 * }
 */
function confirmOrderFixture(array $runOverrides = []): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Confirm order family');
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($team, $member);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $plan = MealPlan::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $owner->id,
    ]);
    $list = ShoppingList::factory()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $owner->id,
        'fulfilment_method' => 'delivery',
        'revision' => 3,
    ]);
    $connection = RetailerConnection::factory()->create([
        'team_id' => $team->id,
        'owner_user_id' => $owner->id,
    ]);

    $slot = [
        'id' => 'slot-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];
    $cartChecksum = hash('sha256', 'cart-for-confirm');

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
        'fulfilment_type' => 'delivery',
        'selected_slot' => $slot,
        'cart_checksum' => $cartChecksum,
        'expires_at' => now()->addHour(),
        ...$runOverrides,
    ]);

    RetailerOrderRunItem::factory()->create([
        'team_id' => $team->id,
        'retailer_order_run_id' => $run->id,
        'shopping_list_item_id' => null,
        'position' => 1,
        'status' => RetailerOrderRunItemStatus::Matched,
        'requirement_snapshot' => [
            'name' => 'Full Cream Milk 2L',
            'quantity' => 1,
            'unit' => 'each',
            'product_match' => [
                'external_id' => '123456',
                'product_name' => 'Full Cream Milk 2L',
                'quantity' => 1,
            ],
        ],
        'matched_product' => [
            'external_id' => '123456',
            'product_name' => 'Full Cream Milk 2L',
            'quantity' => 1,
        ],
        'resolved_at' => now(),
    ]);

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    return compact('owner', 'member', 'outsider', 'run', 'browser', 'slot', 'cartChecksum');
}

it('requires shopper auth and records a fingerprint of cart checksum, fulfilment type, and selected slot', function () {
    $fixture = confirmOrderFixture();

    expect(fn () => app(ConfirmRetailerOrder::class)->handle($fixture['run'], $fixture['outsider']))
        ->toThrow(AuthorizationException::class);

    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    $run = app(ConfirmRetailerOrder::class)->handle($fixture['run'], $fixture['member']);

    $expectedFingerprint = hash('sha256', json_encode([
        'cart_checksum' => $fixture['cartChecksum'],
        'fulfilment_type' => 'delivery',
        'selected_slot' => $fixture['slot'],
    ], JSON_THROW_ON_ERROR));

    expect($run->status)->toBe(RetailerOrderRunStatus::SubmittingOrder)
        ->and($run->confirmation_fingerprint)->toBe($expectedFingerprint)
        ->and($run->confirmation)->toMatchArray([
            'user_id' => $fixture['member']->id,
            'fingerprint' => $expectedFingerprint,
        ])
        ->and($run->confirmation['confirmed_at'] ?? null)->not->toBeNull();
});

it('confirms into SubmittingOrder and dispatches advance', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $fixture = confirmOrderFixture();

    $run = app(ConfirmRetailerOrder::class)->handle($fixture['run'], $fixture['member']);

    expect($run->status)->toBe(RetailerOrderRunStatus::SubmittingOrder);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $job) use ($run): bool {
        return $job->retailerOrderRunId === $run->id;
    });
});

it('places the order when submit succeeds with a parsed reference', function () {
    $fixture = confirmOrderFixture([
        'status' => RetailerOrderRunStatus::SubmittingOrder,
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'fp',
        ],
        'confirmation_fingerprint' => 'fp',
    ]);

    $fixture['browser']->queue('submitOrderWithDefaultPayment', new SubmitResult(
        ok: true,
        retailerOrderReference: null,
        confirmationText: null,
    ));
    $fixture['browser']->queue('extractOrderConfirmation', new SubmitResult(
        ok: true,
        retailerOrderReference: 'WW-123456',
        confirmationText: 'Order WW-123456 confirmed',
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);
    $run = $fixture['run']->refresh();
    $order = Order::query()->where('shopping_list_id', $run->shopping_list_id)->sole();

    expect($result->shouldContinue)->toBeFalse()
        ->and($run->status)->toBe(RetailerOrderRunStatus::Placed)
        ->and($run->retailer_order_reference)->toBe('WW-123456')
        ->and($order->status)->toBe('placed')
        ->and($order->retailer_id)->toBe($run->retailerConnection->retailer_id)
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->first()->product_name)->toBe('Full Cream Milk 2L')
        ->and($order->lines->first()->retailer_product_identifier)->toBe('123456')
        ->and($fixture['browser']->calls)->toHaveCount(2)
        ->and($fixture['browser']->calls[0]['method'])->toBe('submitOrderWithDefaultPayment')
        ->and($fixture['browser']->calls[1]['method'])->toBe('extractOrderConfirmation');
});

it('pauses for placement verification when the reference is unparsed and never resubmits', function () {
    $fixture = confirmOrderFixture([
        'status' => RetailerOrderRunStatus::SubmittingOrder,
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'fp',
        ],
        'confirmation_fingerprint' => 'fp',
    ]);

    $fixture['browser']->queue('submitOrderWithDefaultPayment', new SubmitResult(ok: true));
    $fixture['browser']->queue('extractOrderConfirmation', new SubmitResult(
        ok: true,
        retailerOrderReference: null,
        confirmationText: 'Thanks — your order is being prepared',
    ));

    app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingPlacementVerification)
        ->and($fixture['run']->retailer_order_reference)->toBeNull()
        ->and(Order::query()->where('shopping_list_id', $fixture['run']->shopping_list_id)->exists())->toBeFalse()
        ->and($fixture['run']->status->blocksResubmit())->toBeTrue();

    $submitCallsBefore = collect($fixture['browser']->calls)
        ->where('method', 'submitOrderWithDefaultPayment')
        ->count();

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']->refresh());

    $submitCallsAfter = collect($fixture['browser']->calls)
        ->where('method', 'submitOrderWithDefaultPayment')
        ->count();

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingPlacementVerification)
        ->and($submitCallsBefore)->toBe(1)
        ->and($submitCallsAfter)->toBe(1);
});

it('verifies placement with a manual reference or explicit acknowledgement', function () {
    $fixture = confirmOrderFixture([
        'status' => RetailerOrderRunStatus::AwaitingPlacementVerification,
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'fp',
        ],
        'confirmation_fingerprint' => 'fp',
    ]);

    expect(fn () => app(VerifyRetailerPlacement::class)->handle(
        $fixture['run'],
        $fixture['outsider'],
        retailerOrderReference: 'WW-MANUAL',
    ))->toThrow(AuthorizationException::class);

    $run = app(VerifyRetailerPlacement::class)->handle(
        $fixture['run'],
        $fixture['member'],
        retailerOrderReference: 'WW-MANUAL',
    );
    $order = Order::query()->where('shopping_list_id', $run->shopping_list_id)->sole();

    expect($run->status)->toBe(RetailerOrderRunStatus::Placed)
        ->and($run->retailer_order_reference)->toBe('WW-MANUAL')
        ->and($order->lines)->toHaveCount(1);

    $ackFixture = confirmOrderFixture([
        'status' => RetailerOrderRunStatus::AwaitingPlacementVerification,
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'fp',
        ],
        'confirmation_fingerprint' => 'fp',
    ]);

    $acked = app(VerifyRetailerPlacement::class)->handle(
        $ackFixture['run'],
        $ackFixture['member'],
        acknowledgedPlaced: true,
    );

    expect($acked->status)->toBe(RetailerOrderRunStatus::Placed)
        ->and($acked->retailer_order_reference)->toBeNull()
        ->and(Order::query()->where('shopping_list_id', $acked->shopping_list_id)->exists())->toBeTrue();
});

it('includes default-card-on-file consequence text in the confirm payload helper', function () {
    $fixture = confirmOrderFixture();

    $payload = ConfirmRetailerOrder::confirmationPayload($fixture['run']);

    expect($payload['fulfilment_type'])->toBe('delivery')
        ->and($payload['selected_slot'])->toMatchArray($fixture['slot'])
        ->and($payload['fingerprint'])->toBe(hash('sha256', json_encode([
            'cart_checksum' => $fixture['cartChecksum'],
            'fulfilment_type' => 'delivery',
            'selected_slot' => $fixture['slot'],
        ], JSON_THROW_ON_ERROR)))
        ->and($payload['consequence'])->toContain('default card on file')
        ->and(mb_strtolower($payload['consequence']))->toContain('woolworths');
});

it('rejects confirm outside awaiting order confirmation', function () {
    $fixture = confirmOrderFixture([
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
    ]);

    expect(fn () => app(ConfirmRetailerOrder::class)->handle($fixture['run'], $fixture['member']))
        ->toThrow(ValidationException::class);
});
