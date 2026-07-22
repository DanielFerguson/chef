<?php

use App\Automation\Contracts\BrowserSessionProvider;
use App\Enums\RetailerOrderRunStatus;
use App\Models\BrowserSession;
use App\Retailer\Browserbase\StagehandRetailerBrowser;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\SlotSelection;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function ensureStagehandWorkerBuilt(): string
{
    $workerPath = base_path('automation/dist/src/main.js');

    if (! is_file($workerPath)) {
        $build = new Process(['npm', 'run', 'automation:build'], base_path());
        $build->setTimeout(120);
        $build->run();

        expect($build->isSuccessful())->toBeTrue(
            'automation:build failed: '.$build->getErrorOutput().$build->getOutput(),
        );
    }

    expect($workerPath)->toBeFile();
    config()->set('services.chef_automation.stagehand_worker_path', $workerPath);

    return $workerPath;
}

it('binds FakeRetailerBrowser in testing and keeps StagehandRetailerBrowser available for direct use', function () {
    expect(app(RetailerBrowser::class))->toBeInstanceOf(FakeRetailerBrowser::class)
        ->and(app(StagehandRetailerBrowser::class))->toBeInstanceOf(StagehandRetailerBrowser::class);
});

it('invokes the Stagehand worker for probe_auth and returns a structured AuthCheck', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $auth = $browser->probeAuth($session);

    expect($auth->authenticated)->toBeFalse()
        ->and($auth->reason)->toContain('placeholder')
        ->and($auth->botDetected)->toBeFalse()
        ->and($auth->sensitiveScreen)->toBeFalse();
});

it('invokes the Stagehand worker for inspect_cart and returns a structured CartInspection', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $cart = $browser->inspectCart($session);

    expect($cart->lines)->toBe([])
        ->and($cart->total)->toBe(0.0)
        ->and($cart->currency)->toBe('AUD');
});

it('invokes the Stagehand worker for clear_cart and returns an empty CartInspection', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $cart = $browser->clearCart($session);

    expect($cart->lines)->toBe([])
        ->and($cart->total)->toBe(0.0)
        ->and($cart->currency)->toBe('AUD')
        ->and($cart->botDetected)->toBeFalse();
});

it('invokes the Stagehand worker for add_product and returns a verified ToolResult', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));
    $product = [
        'external_id' => '123456',
        'name' => 'Full Cream Milk 2L',
        'quantity' => 1,
    ];

    $result = $browser->addProduct($session, $product);

    expect($result->ok)->toBeTrue()
        ->and($result->payload['verified'])->toBeTrue()
        ->and($result->payload['status'])->toBe('matched')
        ->and($result->payload['product']['external_id'])->toBe('123456')
        ->and($result->payload['product']['product_name'])->toBe('Full Cream Milk 2L')
        ->and($result->payload['cart']['lines'])->toHaveCount(1);
});

it('invokes fulfilment and submit Stagehand tools with fixture payloads', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $options = $browser->extractFulfilmentOptions($session, 'delivery');
    $applied = $browser->applyFulfilmentSlot($session, new SlotSelection(
        id: 'fixture-slot-1',
        label: 'Tomorrow 8am–10am (delivery)',
        fulfilmentType: 'delivery',
    ));
    $submitted = $browser->submitOrderWithDefaultPayment(
        $session,
        RetailerOrderRunStatus::SubmittingOrder,
    );
    $confirmation = $browser->extractOrderConfirmation($session);

    expect($options->type)->toBe('delivery')
        ->and($options->slots)->toHaveCount(1)
        ->and($options->slots[0]['id'])->toBe('fixture-slot-1')
        ->and($applied->ok)->toBeTrue()
        ->and($applied->payload['slot_id'])->toBe('fixture-slot-1')
        ->and($submitted->ok)->toBeTrue()
        ->and($submitted->confirmationText)->toContain('default card')
        ->and($confirmation->ok)->toBeTrue()
        ->and($confirmation->retailerOrderReference)->toBe('FIXTURE-ORDER-1001');
});

it('refuses Stagehand default-card submit unless the run is SubmittingOrder', function () {
    ensureStagehandWorkerBuilt();

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    expect(fn () => StagehandRetailerBrowser::assertOrderSubmitAllowed(RetailerOrderRunStatus::AwaitingOrderConfirmation))
        ->toThrow(RuntimeException::class, 'submitting_order')
        ->and(fn () => $browser->submitOrderWithDefaultPayment(
            $session,
            RetailerOrderRunStatus::CartReady,
        ))->toThrow(RuntimeException::class, 'submitting_order');

    StagehandRetailerBrowser::assertOrderSubmitAllowed(RetailerOrderRunStatus::SubmittingOrder);

    expect($browser->submitOrderWithDefaultPayment(
        $session,
        RetailerOrderRunStatus::SubmittingOrder,
    )->ok)->toBeTrue();
});
