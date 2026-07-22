<?php

use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Retailer\AdvanceRetailerOrderRun;
use App\Actions\Retailer\ResolveCartDecision;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\ExistingCartDecision;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\ShoppingList;
use App\Models\User;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\AuthCheck;
use App\Retailer\Data\CartInspection;
use App\Retailer\Data\ToolResult;
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
 *     connection: RetailerConnection,
 *     browser: FakeRetailerBrowser,
 *     product: array<string, mixed>,
 * }
 */
function cartDecisionFixture(array $runOverrides = []): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Cart decision family');
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
    ]);
    $connection = RetailerConnection::factory()->create([
        'team_id' => $team->id,
        'owner_user_id' => $owner->id,
        'status' => RetailerConnectionStatus::Connected,
    ]);

    $product = [
        'external_id' => '123456',
        'product_name' => 'Full Cream Milk 2L',
        'product_url' => 'https://www.woolworths.com.au/shop/productdetails/123456',
        'quantity' => 1,
    ];

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::AwaitingCartDecision,
        'existing_cart_decision' => null,
        'expires_at' => now()->addHour(),
        ...$runOverrides,
    ]);

    RetailerOrderRunItem::factory()->create([
        'team_id' => $team->id,
        'retailer_order_run_id' => $run->id,
        'position' => 1,
        'status' => RetailerOrderRunItemStatus::Pending,
        'requirement_snapshot' => [
            'name' => $product['product_name'],
            'quantity' => 1,
            'unit' => 'each',
            'product_match' => $product,
        ],
    ]);

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    return compact('owner', 'member', 'outsider', 'run', 'connection', 'browser', 'product');
}

it('merges by keeping the baseline cart and resuming PreparingCart with advance', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $fixture = cartDecisionFixture();

    $run = app(ResolveCartDecision::class)->handle(
        $fixture['run'],
        $fixture['member'],
        ExistingCartDecision::Merge->value,
    );

    expect($run->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($run->existing_cart_decision)->toBe(ExistingCartDecision::Merge);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $job) use ($run): bool {
        return $job->retailerOrderRunId === $run->id;
    });

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => '999',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
    ]], total: 3.5));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: $fixture['product']));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [
        [
            'external_id' => '999',
            'product_name' => 'Wholemeal bread',
            'quantity' => 1,
        ],
        [
            'external_id' => $fixture['product']['external_id'],
            'product_name' => $fixture['product']['product_name'],
            'quantity' => 1,
        ],
    ], total: 8.0));

    app(AdvanceRetailerOrderRun::class)->handle($run->refresh());

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::CartReady)
        ->and(collect($fixture['browser']->calls)->pluck('method')->all())->not->toContain('clearCart');
});

it('replaces by clearing the remote cart then continuing PreparingCart', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $fixture = cartDecisionFixture();

    $run = app(ResolveCartDecision::class)->handle(
        $fixture['run'],
        $fixture['member'],
        ExistingCartDecision::Replace->value,
    );

    expect($run->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($run->existing_cart_decision)->toBe(ExistingCartDecision::Replace);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class);

    $fixture['browser']->queue('probeAuth', new AuthCheck(authenticated: true));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => '999',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
    ]], total: 3.5));
    $fixture['browser']->queue('clearCart', new CartInspection(lines: [], total: 0.0));
    $fixture['browser']->queue('addProduct', new ToolResult(ok: true, payload: $fixture['product']));
    $fixture['browser']->queue('inspectCart', new CartInspection(lines: [[
        'external_id' => $fixture['product']['external_id'],
        'product_name' => $fixture['product']['product_name'],
        'quantity' => 1,
    ]], total: 4.5));

    app(AdvanceRetailerOrderRun::class)->handle($run->refresh());

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::CartReady)
        ->and(collect($fixture['browser']->calls)->pluck('method')->all())->toContain('clearCart');
});

it('cancels the order run when the cart decision is cancel', function () {
    $fixture = cartDecisionFixture();

    $run = app(ResolveCartDecision::class)->handle(
        $fixture['run'],
        $fixture['member'],
        ExistingCartDecision::Cancel->value,
    );

    expect($run->status)->toBe(RetailerOrderRunStatus::Cancelled)
        ->and($run->status->isTerminal())->toBeTrue();
});

it('rejects cart decisions from outsiders and outside awaiting cart decision', function () {
    $fixture = cartDecisionFixture();

    expect(fn () => app(ResolveCartDecision::class)->handle(
        $fixture['run'],
        $fixture['outsider'],
        ExistingCartDecision::Merge->value,
    ))->toThrow(AuthorizationException::class);

    $fixture['run']->update(['status' => RetailerOrderRunStatus::PreparingCart]);

    expect(fn () => app(ResolveCartDecision::class)->handle(
        $fixture['run']->refresh(),
        $fixture['member'],
        ExistingCartDecision::Merge->value,
    ))->toThrow(ValidationException::class);
});

it('resumes PreparingCart after owner reauthentication for a RetailerOrderRun', function () {
    config()->set('automation.connection_enabled', true);

    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Reauth resume family');
    $plan = MealPlan::factory()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $owner->id,
    ]);
    $list = ShoppingList::factory()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $owner->id,
    ]);

    $loginSession = app(StartRetailerConnection::class)->handle($team, $owner);
    app(VerifyRetailerConnection::class)->handle($loginSession, $owner);
    $connection = $loginSession->retailerConnection->refresh();

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::PreparingCart,
        'expires_at' => now()->addHour(),
    ]);

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);
    $browser->queue('probeAuth', new AuthCheck(
        authenticated: false,
        reason: 'Woolworths session expired.',
    ));

    app(AdvanceRetailerOrderRun::class)->handle($run);

    expect($run->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingReauthentication)
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::ReauthenticationRequired);

    $reauthSession = app(StartRetailerConnection::class)->handle($team, $owner);
    expect($reauthSession->status)->toBe(BrowserSessionStatus::HumanControl)
        ->and($reauthSession->purpose)->toBe(BrowserSessionPurpose::Reauthentication);

    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    app(VerifyRetailerConnection::class)->handle($reauthSession, $owner);

    expect($run->refresh()->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($run->failure_message)->toBeNull()
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($reauthSession->refresh()->purpose)->toBe(BrowserSessionPurpose::CartPreparation)
        ->and($reauthSession->status)->toBe(BrowserSessionStatus::AgentControl)
        ->and($reauthSession->metadata['resumed_after_reauthentication'] ?? null)->toBeTrue();

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $job) use ($run): bool {
        return $job->retailerOrderRunId === $run->id;
    });
});
