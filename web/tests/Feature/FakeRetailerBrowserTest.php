<?php

use App\Models\BrowserSession;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\FulfilmentOptions;
use App\Retailer\Data\SlotSelection;
use App\Retailer\Data\SubmitResult;
use App\Retailer\Data\ToolResult;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds FakeRetailerBrowser in the testing environment', function () {
    expect(app(RetailerBrowser::class))->toBeInstanceOf(FakeRetailerBrowser::class);
});

it('returns scripted responses and records calls for every retailer browser tool', function () {
    $session = BrowserSession::factory()->create();
    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    $auth = new AuthCheck(authenticated: true, reason: 'Protected cart probe passed.');
    $emptyCart = new CartInspection(lines: [], total: 0.0);
    $clearedCart = new CartInspection(lines: [], total: 0.0);
    $addResult = new ToolResult(ok: true, payload: [
        'external_id' => '123456',
        'name' => 'Full Cream Milk 2L',
        'quantity' => 2,
    ]);
    $options = new FulfilmentOptions(
        type: 'delivery',
        slots: [[
            'id' => 'slot-1',
            'label' => 'Tomorrow 6–8pm',
            'starts_at' => '2026-07-23T18:00:00+10:00',
            'ends_at' => '2026-07-23T20:00:00+10:00',
            'fee' => 0.0,
        ]],
        expiresAt: now()->addMinutes(30),
    );
    $applyResult = new ToolResult(ok: true, payload: ['slot_id' => 'slot-1']);
    $submit = new SubmitResult(ok: true, retailerOrderReference: null, confirmationText: null);
    $confirmation = new SubmitResult(
        ok: true,
        retailerOrderReference: 'WW-987654',
        confirmationText: 'Order WW-987654 confirmed',
    );

    $browser->queue('probeAuth', $auth);
    $browser->queue('inspectCart', $emptyCart);
    $browser->queue('clearCart', $clearedCart);
    $browser->queue('addProduct', $addResult);
    $browser->queue('extractFulfilmentOptions', $options);
    $browser->queue('applyFulfilmentSlot', $applyResult);
    $browser->queue('submitOrderWithDefaultPayment', $submit);
    $browser->queue('extractOrderConfirmation', $confirmation);

    $product = [
        'external_id' => '123456',
        'name' => 'Full Cream Milk 2L',
        'quantity' => 2,
    ];
    $slot = new SlotSelection(
        id: 'slot-1',
        label: 'Tomorrow 6–8pm',
        startsAt: '2026-07-23T18:00:00+10:00',
        endsAt: '2026-07-23T20:00:00+10:00',
        fee: 0.0,
        fulfilmentType: 'delivery',
    );

    expect($browser->probeAuth($session))->toBe($auth)
        ->and($browser->inspectCart($session))->toBe($emptyCart)
        ->and($browser->clearCart($session))->toBe($clearedCart)
        ->and($browser->addProduct($session, $product))->toBe($addResult)
        ->and($browser->extractFulfilmentOptions($session, 'delivery'))->toBe($options)
        ->and($browser->applyFulfilmentSlot($session, $slot))->toBe($applyResult)
        ->and($browser->submitOrderWithDefaultPayment($session))->toBe($submit)
        ->and($browser->extractOrderConfirmation($session))->toBe($confirmation);

    expect($browser->calls)->toHaveCount(8)
        ->and($browser->calls[0])->toMatchArray(['method' => 'probeAuth', 'session_id' => $session->id])
        ->and($browser->calls[1]['method'])->toBe('inspectCart')
        ->and($browser->calls[2]['method'])->toBe('clearCart')
        ->and($browser->calls[3])->toMatchArray([
            'method' => 'addProduct',
            'product' => $product,
        ])
        ->and($browser->calls[4])->toMatchArray([
            'method' => 'extractFulfilmentOptions',
            'fulfilment_type' => 'delivery',
        ])
        ->and($browser->calls[5])->toMatchArray([
            'method' => 'applyFulfilmentSlot',
            'slot' => $slot,
        ])
        ->and($browser->calls[6]['method'])->toBe('submitOrderWithDefaultPayment')
        ->and($browser->calls[7]['method'])->toBe('extractOrderConfirmation');
});
