<?php

use App\Actions\Automation\StartCartPreparation;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Teams\CreateTeamForUser;
use App\Automation\Browserbase\BrowserbaseBrowserSessionProvider;
use App\Automation\Browserbase\WoolworthsCatalogueDiscovery;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Automation\OpenAI\OpenAIComputerUseClient;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\MealPlan;
use App\Models\Retailer;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

/** @return array<string, mixed> */
function providerCartWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Provider family');
    $plan = MealPlan::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'revision' => 1,
        'planning_confirmed_at' => now(),
    ]);
    $list = ShoppingList::factory()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $user->id,
        'revision' => 1,
        'source_plan_revision' => 1,
    ]);
    $item = ShoppingListItem::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'name' => 'Milk',
        'normalized_name' => 'milk',
    ]);
    $revision = ShoppingListRevision::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'user_id' => $user->id,
        'revision' => 1,
        'snapshot' => [
            'source_plan_revision' => 1,
            'status' => 'draft',
            'items' => [[
                'id' => $item->id,
                'name' => 'Milk',
                'quantity' => 1,
                'unit' => 'litre',
                'included' => true,
                'in_pantry' => false,
                'optional' => false,
                'product_match' => null,
                'source_planned_meal_ids' => [],
            ]],
        ],
    ]);

    return compact('user', 'team', 'plan', 'list', 'item', 'revision');
}

function providerConnectedWoolworths(array $workspace): RetailerConnection
{
    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);

    return $session->retailerConnection->refresh();
}

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

it('uses the direct Responses computer-call protocol without persisting screenshots', function () {
    $workspace = providerCartWorkspace();
    $connection = providerConnectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('services.openai.base_url', 'https://api.openai.test');
    config()->set('services.openai.store', false);
    config()->set('services.openai.computer_use_model', 'gpt-5.6-sol');
    config()->set('services.openai.computer_use_reasoning_effort', 'low');
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
        true,
        true,
    );
    $item = $run->items()->sole();
    $screenshot = 'data:image/png;base64,'.base64_encode('transient pixels');

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Automation/openai-computer-ga.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    Http::fakeSequence('api.openai.test/*')
        ->push($fixture['initial_response'])
        ->push($fixture['continuation_response']);

    $client = new OpenAIComputerUseClient;
    $first = $client->next($run, $item, $screenshot);
    $run->update(['openai_response_id' => $first->responseId]);
    $second = $client->next($run->refresh(), $item, $screenshot, [
        'call_id' => $first->callId,
        'acknowledged_safety_checks' => [],
    ]);

    expect($first->actions)->toHaveCount(2)
        ->and($first->actions[0]['type'])->toBe('click')
        ->and($first->actions[1]['type'])->toBe('keypress')
        ->and($first->pendingSafetyChecks)->toBe([])
        ->and($second->complete)->toBeTrue()
        ->and($second->message)->toBe('Done')
        ->and(DB::table('automation_steps')->where('input_summary', 'like', '%transient pixels%')->exists())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/responses')
        && $request['store'] === false
        && $request['model'] === 'gpt-5.6-sol'
        && $request['tools'] === [['type' => 'computer']]
        && $request['reasoning']['effort'] === 'low'
        && data_get($request->data(), 'input.0.content.1.image_url') === $screenshot);
    Http::assertSent(fn (Request $request) => ($request['previous_response_id'] ?? null) === 'resp_ga_1'
        && $request['input'][0]['type'] === 'computer_call_output'
        && $request['input'][0]['call_id'] === 'call_ga_1'
        && $request['input'][0]['output']['detail'] === 'original');
});

it('reports automation readiness and fails closed on incomplete enabled provider configuration', function () {
    $this->app['env'] = 'local';
    config()->set('services.browserbase.api_key', 'secret-test-key');
    config()->set('services.browserbase.project_id', 'project-test');
    config()->set('services.openai.api_key', 'secret-openai-key');
    config()->set('services.chef_automation.worker_path', __FILE__);
    config()->set('services.chef_automation.actor_path', __FILE__);
    config()->set('services.chef_automation.actor_launcher_path', __FILE__);
    config()->set('automation.connection_enabled', false);
    config()->set('automation.queue', 'automation');
    config()->set('services.browserbase.record_local_cart_sessions', true);

    $this->artisan('chef:automation:status')
        ->expectsOutputToContain('Connection flag: disabled')
        ->expectsOutputToContain('Local cart-session recording: enabled')
        ->expectsOutputToContain('Browser viewport: 1024 × 768')
        ->expectsOutputToContain('Computer-use model: gpt-5.6-sol')
        ->expectsOutputToContain('Automation queue: automation')
        ->assertSuccessful();

    config()->set('services.browserbase.project_id', null);
    config()->set('automation.connection_enabled', true);

    $this->artisan('chef:automation:status')
        ->expectsOutputToContain('Connection mode is enabled without complete Browserbase configuration.')
        ->assertFailed();
});
