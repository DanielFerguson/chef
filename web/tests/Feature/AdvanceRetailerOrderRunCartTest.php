<?php

use App\Actions\Retailer\AdvanceRetailerOrderRun;
use App\Enums\BrowserSessionPurpose;
use App\Enums\ExistingCartDecision;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\BrowserSession;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\ToolResult;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * @return array{run: RetailerOrderRun, connection: RetailerConnection, browser: FakeRetailerBrowser, product: array<string, mixed>}
 */
function cartAdvanceFixture(int $itemCount = 1): array
{
    $run = RetailerOrderRun::factory()->create([
        'status' => RetailerOrderRunStatus::PreparingCart,
        'limits' => [
            'max_actions' => 40,
            'max_runtime_seconds' => 180,
            'max_item_attempts' => 4,
        ],
        'expires_at' => now()->addHour(),
    ]);

    $product = [
        'external_id' => '123456',
        'product_name' => 'Full Cream Milk 2L',
        'product_url' => 'https://www.woolworths.com.au/shop/productdetails/123456',
        'quantity' => 1,
    ];

    for ($position = 1; $position <= $itemCount; $position++) {
        $itemProduct = [
            ...$product,
            'external_id' => (string) (123456 + $position - 1),
            'product_name' => $position === 1 ? 'Full Cream Milk 2L' : "Grocery item {$position}",
        ];

        RetailerOrderRunItem::factory()->create([
            'team_id' => $run->team_id,
            'retailer_order_run_id' => $run->id,
            'position' => $position,
            'status' => RetailerOrderRunItemStatus::Pending,
            'requirement_snapshot' => [
                'name' => $itemProduct['product_name'],
                'quantity' => 1,
                'unit' => 'each',
                'product_match' => $itemProduct,
            ],
        ]);
    }

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    return [
        'run' => $run->refresh()->load('items'),
        'connection' => $run->retailerConnection,
        'browser' => $browser,
        'product' => $product,
    ];
}

it('pauses for reauthentication when the auth probe fails', function () {
    $fixture = cartAdvanceFixture();
    $fixture['browser']->queue('probeAuth', new AuthCheck(
        authenticated: false,
        reason: 'Woolworths session expired.',
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingReauthentication)
        ->and($fixture['run']->failure_message)->toBe('Woolworths session expired.')
        ->and($fixture['connection']->refresh()->status)->toBe(RetailerConnectionStatus::ReauthenticationRequired);
});

it('awaits a cart decision when the remote cart already has items', function () {
    $fixture = cartAdvanceFixture();
    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(
        lines: [[
            'external_id' => '999',
            'product_name' => 'Wholemeal bread',
            'quantity' => 1,
        ]],
        total: 3.5,
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingCartDecision)
        ->and($fixture['run']->existing_cart_decision)->toBeNull();
});

it('no-ops while awaiting a cart decision until Task 10 resolves it', function () {
    $fixture = cartAdvanceFixture();
    $fixture['run']->update(['status' => RetailerOrderRunStatus::AwaitingCartDecision]);

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']->refresh());

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingCartDecision)
        ->and($fixture['browser']->calls)->toBe([]);
});

it('adds products to an empty cart, verifies lines, and stops at CartReady', function () {
    $fixture = cartAdvanceFixture();
    $product = $fixture['run']->items->sole()->requirement_snapshot['product_match'];

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [], total: 0.0));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: [
        'external_id' => $product['external_id'],
        'name' => $product['product_name'],
        'quantity' => 1,
    ]));
    $fixture['browser']->queue('inspectCart', new CartInspection(
        lines: [[
            'external_id' => $product['external_id'],
            'product_name' => $product['product_name'],
            'quantity' => 1,
        ]],
        total: 4.5,
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    $item = $fixture['run']->items()->sole()->refresh();

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::CartReady)
        ->and($item->status)->toBe(RetailerOrderRunItemStatus::Matched)
        ->and($item->matched_product['external_id'])->toBe($product['external_id'])
        ->and($item->resolved_at)->not->toBeNull()
        ->and($fixture['browser']->calls[0]['method'])->toBe('probeAuth')
        ->and($fixture['browser']->calls[1]['method'])->toBe('inspectCart')
        ->and($fixture['browser']->calls[2])->toMatchArray([
            'method' => 'addProduct',
            'product' => [
                'external_id' => $product['external_id'],
                'name' => $product['product_name'],
                'quantity' => 1,
            ],
        ])
        ->and($fixture['browser']->calls[3]['method'])->toBe('inspectCart')
        ->and(BrowserSession::query()->where('retailer_connection_id', $fixture['connection']->id)->exists())->toBeTrue()
        ->and(BrowserSession::query()->where('retailer_connection_id', $fixture['connection']->id)->sole()->purpose)
        ->toBe(BrowserSessionPurpose::CartPreparation);
});

it('fails safely when the retailer presents bot detection or a sensitive screen', function () {
    $fixture = cartAdvanceFixture();
    $fixture['browser']->queue('probeAuth', new AuthCheck(
        authenticated: true,
        reason: 'Woolworths presented bot detection.',
        botDetected: true,
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::Failed)
        ->and($fixture['run']->failure_message)->toContain('bot detection');
});

it('fails safely when cart inspection hits a sensitive screen', function () {
    $fixture = cartAdvanceFixture();
    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(
        lines: [],
        sensitiveScreen: true,
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::Failed)
        ->and($fixture['run']->failure_message)->toContain('sensitive');
});

it('chunks cart work and returns shouldContinue until every item is matched', function () {
    config()->set('automation.max_actions_per_job', 1);
    $fixture = cartAdvanceFixture(itemCount: 2);

    $first = $fixture['run']->items->firstWhere('position', 1)->requirement_snapshot['product_match'];
    $second = $fixture['run']->items->firstWhere('position', 2)->requirement_snapshot['product_match'];

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [], total: 0.0));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: [
        'external_id' => $first['external_id'],
        'name' => $first['product_name'],
        'quantity' => 1,
    ]));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => $first['external_id'],
        'product_name' => $first['product_name'],
        'quantity' => 1,
    ]], total: 4.5));

    $firstResult = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    expect($firstResult->shouldContinue)->toBeTrue()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($fixture['run']->items()->where('status', RetailerOrderRunItemStatus::Matched)->count())->toBe(1)
        ->and($fixture['run']->items()->where('status', RetailerOrderRunItemStatus::Pending)->count())->toBe(1);

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => $first['external_id'],
        'product_name' => $first['product_name'],
        'quantity' => 1,
    ]], total: 4.5));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: [
        'external_id' => $second['external_id'],
        'name' => $second['product_name'],
        'quantity' => 1,
    ]));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [
        [
            'external_id' => $first['external_id'],
            'product_name' => $first['product_name'],
            'quantity' => 1,
        ],
        [
            'external_id' => $second['external_id'],
            'product_name' => $second['product_name'],
            'quantity' => 1,
        ],
    ], total: 9.0));

    $secondResult = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']->refresh());

    expect($secondResult->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::CartReady)
        ->and($fixture['run']->items()->where('status', RetailerOrderRunItemStatus::Matched)->count())->toBe(2);
});

it('uses a unique overlapping-safe job that re-dispatches while shouldContinue', function () {
    $job = new AdvanceRetailerOrderRunJob(42);

    expect($job->uniqueId())->toBe('retailer-order-run:42')
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);

    config()->set('queue.default', 'database');
    config()->set('automation.max_actions_per_job', 1);
    $fixture = cartAdvanceFixture(itemCount: 2);
    $first = $fixture['run']->items->firstWhere('position', 1)->requirement_snapshot['product_match'];

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [], total: 0.0));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: [
        'external_id' => $first['external_id'],
        'name' => $first['product_name'],
        'quantity' => 1,
    ]));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => $first['external_id'],
        'product_name' => $first['product_name'],
        'quantity' => 1,
    ]], total: 4.5));

    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    (new AdvanceRetailerOrderRunJob($fixture['run']->id))->handle(app(AdvanceRetailerOrderRun::class));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($fixture['run']->items()->where('status', RetailerOrderRunItemStatus::Matched)->count())->toBe(1);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $dispatched) use ($fixture): bool {
        return $dispatched->retailerOrderRunId === $fixture['run']->id;
    });
});

it('accepts an existing merge decision and continues cart preparation', function () {
    $fixture = cartAdvanceFixture();
    $product = $fixture['run']->items->sole()->requirement_snapshot['product_match'];
    $fixture['run']->update([
        'existing_cart_decision' => ExistingCartDecision::Merge,
    ]);

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => '999',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
    ]], total: 3.5));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: [
        'external_id' => $product['external_id'],
        'name' => $product['product_name'],
        'quantity' => 1,
    ]));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [
        [
            'external_id' => '999',
            'product_name' => 'Wholemeal bread',
            'quantity' => 1,
        ],
        [
            'external_id' => $product['external_id'],
            'product_name' => $product['product_name'],
            'quantity' => 1,
        ],
    ], total: 8.0));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']->refresh());

    expect($result->shouldContinue)->toBeFalse()
        ->and($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::CartReady)
        ->and($fixture['run']->items()->sole()->status)->toBe(RetailerOrderRunItemStatus::Matched);
});
