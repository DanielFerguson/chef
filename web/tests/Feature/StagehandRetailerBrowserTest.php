<?php

use App\Automation\Contracts\BrowserSessionProvider;
use App\Models\BrowserSession;
use App\Retailer\Browserbase\StagehandRetailerBrowser;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('binds FakeRetailerBrowser in testing and keeps StagehandRetailerBrowser available for direct use', function () {
    expect(app(RetailerBrowser::class))->toBeInstanceOf(FakeRetailerBrowser::class)
        ->and(app(StagehandRetailerBrowser::class))->toBeInstanceOf(StagehandRetailerBrowser::class);
});

it('invokes the Stagehand worker for probe_auth and returns a structured AuthCheck', function () {
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

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $auth = $browser->probeAuth($session);

    expect($auth->authenticated)->toBeFalse()
        ->and($auth->reason)->toContain('placeholder')
        ->and($auth->botDetected)->toBeFalse()
        ->and($auth->sensitiveScreen)->toBeFalse();
});

it('invokes the Stagehand worker for inspect_cart and returns a structured CartInspection', function () {
    $workerPath = base_path('automation/dist/src/main.js');
    expect($workerPath)->toBeFile();

    config()->set('services.chef_automation.stagehand_worker_path', $workerPath);

    $session = BrowserSession::factory()->create();
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));

    $cart = $browser->inspectCart($session);

    expect($cart->lines)->toBe([])
        ->and($cart->total)->toBe(0.0)
        ->and($cart->currency)->toBe('AUD');
});

it('throws not implemented for tools that are not wired yet', function () {
    $browser = new StagehandRetailerBrowser(app(BrowserSessionProvider::class));
    $session = BrowserSession::factory()->create();

    expect(fn () => $browser->clearCart($session))
        ->toThrow(RuntimeException::class, 'not implemented');
});
