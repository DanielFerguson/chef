<?php

use App\Automation\Browserbase\BrowserbaseBrowserSessionProvider;
use App\Automation\Browserbase\WoolworthsCatalogueDiscovery;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\Retailer;
use App\Models\RetailerConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('discovers bounded Woolworths candidates without opening an authenticated browser', function () {
    $retailer = Retailer::query()->firstOrCreate(
        ['slug' => 'woolworths'],
        ['name' => 'Woolworths', 'active' => true],
    );
    config()->set('services.woolworths.catalogue_search_url', 'https://www.woolworths.com.au/apis/ui/Search/products');
    config()->set('services.woolworths.discovery_concurrency', 2);
    Http::fake([
        'www.woolworths.com.au/apis/ui/Search/products*' => Http::response([
            'Products' => [[
                'Stockcode' => 123456,
                'Name' => 'Woolworths Full Cream Milk 2L',
                'Price' => 4.5,
                'PackageSize' => '2L',
                'CupString' => '$2.25 / 1L',
                'IsInStock' => true,
            ]],
        ]),
    ]);

    $results = app(WoolworthsCatalogueDiscovery::class)->discover($retailer, [[
        'name' => 'full cream milk',
        'quantity' => 2,
        'unit' => 'litre',
    ]]);

    expect($results)->toHaveCount(1)
        ->and($results[0][0]['external_id'])->toBe('123456')
        ->and($results[0][0]['product_url'])->toBe('https://www.woolworths.com.au/shop/productdetails/123456')
        ->and($results[0][0]['pack_size'])->toBe('2L')
        ->and($results[0][0]['pack_count'])->toBe(1)
        ->and($results[0][0]['source'])->toBe('woolworths_public_catalogue');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('User-Agent', 'Chef product-plan discovery'));
});

it('uses catalogue relevance and computes the packs needed for a confident ingredient match', function () {
    $retailer = Retailer::query()->firstOrCreate(
        ['slug' => 'woolworths'],
        ['name' => 'Woolworths', 'active' => true],
    );
    Http::fake([
        'www.woolworths.com.au/apis/ui/Search/products*' => Http::response([
            'Products' => [
                ['Products' => [[
                    'Stockcode' => 1127633937,
                    'Name' => "Mr Fothergill's Spinach Seeds",
                    'Price' => 4.5,
                    'PackageSize' => '',
                    'IsAvailable' => true,
                    'Source' => 'SearchServiceSearchProducts.Promoted',
                    'IsMarketProduct' => true,
                ]]],
                ['Products' => [[
                    'Stockcode' => 524322,
                    'Name' => 'Woolworths Baby Leaf Spinach',
                    'Price' => 3.3,
                    'PackageSize' => '120g',
                    'IsAvailable' => true,
                    'Source' => 'SearchServiceSearchProducts',
                ]]],
            ],
        ]),
    ]);

    $results = app(WoolworthsCatalogueDiscovery::class)->discover($retailer, [[
        'name' => 'baby spinach',
        'quantity' => 180,
        'unit' => 'g',
    ]]);

    expect($results[0][0]['product_name'])->toBe('Woolworths Baby Leaf Spinach')
        ->and($results[0][0]['confidence'])->toBeGreaterThan(0.92)
        ->and($results[0][0]['pack_size'])->toBe('120g')
        ->and($results[0][0]['pack_count'])->toBe(2)
        ->and($results[0])->toHaveCount(1);
});

it('does not misclassify a rejected Woolworths catalogue request as no candidates', function () {
    $retailer = Retailer::query()->firstOrCreate(
        ['slug' => 'woolworths'],
        ['name' => 'Woolworths', 'active' => true],
    );
    Http::fake([
        'www.woolworths.com.au/apis/ui/Search/products*' => Http::response([], 403),
    ]);

    expect(fn () => app(WoolworthsCatalogueDiscovery::class)->discover($retailer, [[
        'name' => 'avocado',
        'quantity' => 1,
        'unit' => 'each',
    ]]))->toThrow(RuntimeException::class, 'did not return a usable response');
});

it('records only explicitly enabled local cart preparation Browserbase sessions', function () {
    $this->app['env'] = 'local';
    config()->set('services.browserbase.api_key', 'test-browserbase-key');
    config()->set('services.browserbase.project_id', 'test-project');
    config()->set('services.browserbase.base_url', 'https://api.browserbase.test');
    config()->set('services.browserbase.region', 'ap-southeast-1');
    config()->set('services.browserbase.proxy_city', 'MELBOURNE');
    config()->set('services.browserbase.proxy_country', 'AU');
    config()->set('services.browserbase.record_local_cart_sessions', true);

    Http::fake(function (Request $request) {
        $path = $request->url();

        if ($request->method() === 'POST' && str_ends_with($path, '/v1/contexts')) {
            return Http::response(['id' => 'ctx-test'], 201);
        }

        if ($request->method() === 'POST' && str_ends_with($path, '/v1/sessions')) {
            return Http::response([
                'id' => 'session-test',
                'expiresAt' => '2026-07-21T08:56:59.000Z',
            ], 201);
        }

        if ($request->method() === 'GET' && str_ends_with($path, '/v1/sessions/session-test/debug')) {
            return Http::response(['debuggerFullscreenUrl' => 'https://live.browserbase.test/token'], 200);
        }

        if ($request->method() === 'GET' && str_ends_with($path, '/v1/sessions/session-test')) {
            return Http::response(['connectUrl' => 'wss://connect.browserbase.test/token'], 200);
        }

        if ($request->method() === 'POST' && str_ends_with($path, '/v1/sessions/session-test')) {
            return Http::response(['id' => 'session-test', 'status' => 'COMPLETED'], 200);
        }

        if ($request->method() === 'DELETE' && str_ends_with($path, '/v1/contexts/ctx-test')) {
            return Http::response(null, 204);
        }

        return Http::response([], 404);
    });

    $connection = RetailerConnection::factory()->create(['provider_context_id' => 'ctx-test']);
    $provider = new BrowserbaseBrowserSessionProvider;
    expect($provider->createContext())->toBe('ctx-test');
    $created = $provider->createSession($connection, BrowserSessionPurpose::Login);
    $recorded = $provider->createSession($connection, BrowserSessionPurpose::CartPreparation);
    expect($created->expiresAt?->format('Y-m-d H:i:s P'))->toBe('2026-07-21 18:56:59 +10:00')
        ->and($created->recordingEnabled)->toBeFalse()
        ->and($recorded->recordingEnabled)->toBeTrue();
    $session = BrowserSession::factory()->create([
        'team_id' => $connection->team_id,
        'retailer_connection_id' => $connection->id,
        'provider_session_id' => $created->id,
        'purpose' => BrowserSessionPurpose::Login,
    ]);

    expect($provider->connectionUrl($session))->toBe('wss://connect.browserbase.test/token')
        ->and($provider->liveViewUrl($session))->toBe('https://live.browserbase.test/token');
    $provider->close($session);
    $provider->deleteContext($connection);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/sessions')
        && $request['browserSettings']['recordSession'] === false
        && $request['userMetadata']['purpose'] === BrowserSessionPurpose::Login->value
        && $request['browserSettings']['context'] === ['id' => 'ctx-test', 'persist' => true]
        && $request['browserSettings']['viewport'] === ['width' => 1024, 'height' => 768]
        && $request['region'] === 'ap-southeast-1'
        && $request['proxies'][0]['geolocation'] === ['city' => 'MELBOURNE', 'country' => 'AU']);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/sessions')
        && $request['browserSettings']['recordSession'] === true
        && $request['userMetadata']['purpose'] === BrowserSessionPurpose::CartPreparation->value);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/sessions/session-test')
        && $request['status'] === 'REQUEST_RELEASE');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/contexts/ctx-test'));
});

it('maps missing Browserbase Contexts and sessions to recoverable domain failures', function () {
    config()->set('services.browserbase.api_key', 'test-browserbase-key');
    config()->set('services.browserbase.project_id', 'test-project');
    config()->set('services.browserbase.base_url', 'https://api.browserbase.test');
    Http::fake(fn () => Http::response([], 410));
    $connection = RetailerConnection::factory()->create(['provider_context_id' => 'revoked-context']);
    $session = BrowserSession::factory()->create([
        'team_id' => $connection->team_id,
        'retailer_connection_id' => $connection->id,
        'provider_session_id' => 'lost-session',
        'purpose' => BrowserSessionPurpose::CartPreparation,
    ]);
    $provider = new BrowserbaseBrowserSessionProvider;

    expect(fn () => $provider->createSession($connection, BrowserSessionPurpose::CartPreparation))
        ->toThrow(RetailerContextRevokedException::class)
        ->and(fn () => $provider->connectionUrl($session))
        ->toThrow(BrowserSessionLostException::class)
        ->and(fn () => $provider->liveViewUrl($session))
        ->toThrow(BrowserSessionLostException::class);

    $provider->close($session);
    $provider->deleteContext($connection);
});

it('maps completed Browserbase sessions without a CDP URL to a lost session', function () {
    config()->set('services.browserbase.api_key', 'test-browserbase-key');
    config()->set('services.browserbase.project_id', 'test-project');
    config()->set('services.browserbase.base_url', 'https://api.browserbase.test');
    Http::fake(fn () => Http::response(['id' => 'completed-session', 'status' => 'COMPLETED'], 200));
    $connection = RetailerConnection::factory()->create(['provider_context_id' => 'ctx-test']);
    $session = BrowserSession::factory()->create([
        'team_id' => $connection->team_id,
        'retailer_connection_id' => $connection->id,
        'provider_session_id' => 'completed-session',
        'purpose' => BrowserSessionPurpose::Reauthentication,
    ]);

    expect(fn () => (new BrowserbaseBrowserSessionProvider)->connectionUrl($session))
        ->toThrow(BrowserSessionLostException::class);
});

it('reports automation readiness and fails closed on incomplete enabled provider configuration', function () {
    $this->app['env'] = 'local';
    config()->set('services.browserbase.api_key', 'secret-test-key');
    config()->set('services.browserbase.project_id', 'project-test');
    config()->set('services.chef_automation.worker_path', __FILE__);
    config()->set('services.chef_automation.actor_path', __FILE__);
    config()->set('services.chef_automation.actor_launcher_path', __FILE__);
    config()->set('services.chef_automation.stagehand_worker_path', __FILE__);
    config()->set('automation.connection_enabled', false);
    config()->set('automation.queue', 'automation');
    config()->set('services.browserbase.record_local_cart_sessions', true);

    $this->artisan('chef:automation:status')
        ->expectsOutputToContain('Connection flag: disabled')
        ->expectsOutputToContain('Local cart-session recording: enabled')
        ->expectsOutputToContain('Browser viewport: 1024 × 768')
        ->expectsOutputToContain('Automation queue: automation')
        ->assertSuccessful();

    config()->set('services.browserbase.project_id', null);
    config()->set('automation.connection_enabled', true);

    $this->artisan('chef:automation:status')
        ->expectsOutputToContain('Connection mode is enabled without complete Browserbase configuration.')
        ->assertFailed();
});
