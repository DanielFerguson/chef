<?php

use App\Actions\Automation\StartCartPreparation;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Teams\CreateTeamForUser;
use App\Automation\Browserbase\BrowserbaseBrowserSessionProvider;
use App\Automation\Exceptions\BrowserSessionLostException;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Automation\OpenAI\OpenAIComputerUseClient;
use App\Enums\BrowserSessionPurpose;
use App\Models\BrowserSession;
use App\Models\MealPlan;
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

it('creates recording-disabled persistent Australian Browserbase sessions', function () {
    config()->set('services.browserbase.api_key', 'test-browserbase-key');
    config()->set('services.browserbase.project_id', 'test-project');
    config()->set('services.browserbase.base_url', 'https://api.browserbase.test');
    config()->set('services.browserbase.region', 'ap-southeast-1');
    config()->set('services.browserbase.proxy_city', 'MELBOURNE');
    config()->set('services.browserbase.proxy_country', 'AU');

    Http::fake(function (Request $request) {
        $path = $request->url();

        if ($request->method() === 'POST' && str_ends_with($path, '/v1/contexts')) {
            return Http::response(['id' => 'ctx-test'], 201);
        }

        if ($request->method() === 'POST' && str_ends_with($path, '/v1/sessions')) {
            return Http::response([
                'id' => 'session-test',
                'expiresAt' => now()->addMinutes(15)->toIso8601String(),
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
        && $request['browserSettings']['context'] === ['id' => 'ctx-test', 'persist' => true]
        && $request['region'] === 'ap-southeast-1'
        && $request['proxies'][0]['geolocation'] === ['city' => 'MELBOURNE', 'country' => 'AU']);
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

it('uses the direct Responses computer-call protocol without persisting screenshots', function () {
    $workspace = providerCartWorkspace();
    $connection = providerConnectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('services.openai.base_url', 'https://api.openai.test');
    config()->set('services.openai.store', false);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $item = $run->items()->sole();
    $screenshot = 'data:image/png;base64,'.base64_encode('transient pixels');

    Http::fakeSequence('api.openai.test/*')
        ->push([
            'id' => 'resp_1',
            'output' => [[
                'type' => 'computer_call',
                'call_id' => 'call_1',
                'action' => ['type' => 'click', 'x' => 100, 'y' => 120, 'button' => 'left'],
                'pending_safety_checks' => [],
            ]],
        ])
        ->push([
            'id' => 'resp_2',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Done']],
            ]],
        ]);

    $client = new OpenAIComputerUseClient;
    $first = $client->next($run, $item, $screenshot);
    $run->update(['openai_response_id' => $first->responseId]);
    $second = $client->next($run->refresh(), $item, $screenshot, [
        'call_id' => $first->callId,
        'acknowledged_safety_checks' => [],
    ]);

    expect($first->action['type'])->toBe('click')
        ->and($first->pendingSafetyChecks)->toBe([])
        ->and($second->complete)->toBeTrue()
        ->and($second->message)->toBe('Done')
        ->and(DB::table('automation_steps')->where('input_summary', 'like', '%transient pixels%')->exists())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/responses')
        && $request['store'] === false
        && $request['tools'][0]['type'] === 'computer_use_preview'
        && data_get($request->data(), 'input.0.content.1.image_url') === $screenshot);
    Http::assertSent(fn (Request $request) => ($request['previous_response_id'] ?? null) === 'resp_1'
        && $request['input'][0]['type'] === 'computer_call_output'
        && $request['input'][0]['call_id'] === 'call_1');
});
