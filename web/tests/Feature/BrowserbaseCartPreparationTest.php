<?php

use App\Actions\Automation\CreateBrowserSession;
use App\Actions\Automation\DisconnectRetailerConnection;
use App\Actions\Automation\FinishAutomationTakeover;
use App\Actions\Automation\ResolveAutomationIntervention;
use App\Actions\Automation\StartAutomationTakeover;
use App\Actions\Automation\StartCartPreparation;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Contracts\ComputerUseEngine;
use App\Automation\Exceptions\RetailerContextRevokedException;
use App\Automation\Testing\FakeBrowserSessionProvider;
use App\Automation\Testing\FakeComputerExecutor;
use App\Enums\AutomationInterventionType;
use App\Enums\AutomationRunStatus;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationIntervention;
use App\Models\MealPlan;
use App\Models\ProductPreference;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListRevision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** @return array{user: User, team: Team, plan: MealPlan, list: ShoppingList, item: ShoppingListItem, revision: ShoppingListRevision} */
function browserbaseCartWorkspace(string $itemName = 'Full cream milk'): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Automation family');
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
        'created_by_user_id' => $user->id,
        'name' => $itemName,
        'normalized_name' => Str::lower($itemName),
        'quantity' => 1,
        'unit' => 'litre',
        'position' => 1,
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
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'note' => null,
                'included' => true,
                'in_pantry' => false,
                'checked' => false,
                'optional' => false,
                'estimated_price' => 4.5,
                'product_match' => null,
                'source_planned_meal_ids' => [],
            ]],
        ],
    ]);

    return compact('user', 'team', 'plan', 'list', 'item', 'revision');
}

function connectedWoolworths(array $workspace): RetailerConnection
{
    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);

    return $session->retailerConnection->refresh();
}

it('keeps Browserbase authentication owner-only encrypted and recording-disabled', function () {
    $workspace = browserbaseCartWorkspace();
    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    $connection = $session->retailerConnection;

    expect($connection->provider_context_id)->toBe('fake-context-1')
        ->and(DB::table('retailer_connections')->where('id', $connection->id)->value('provider_context_id'))->not->toBe('fake-context-1')
        ->and($session->provider_session_id)->toBe('fake-session-1')
        ->and(DB::table('browser_sessions')->where('id', $session->id)->value('provider_session_id'))->not->toBe('fake-session-1')
        ->and($session->recording_enabled)->toBeFalse();

    $otherMember = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $otherMember);
    expect(fn () => app(VerifyRetailerConnection::class)->handle($session, $otherMember))
        ->toThrow(AuthorizationException::class);

    $this->withoutVite();
    $this->actingAs($workspace['user'])
        ->get(route('browser-sessions.authenticate.show', $session))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('retailer-connections/authenticate')
            ->where('session.recording_enabled', false)
            ->where('session.live_view_endpoint', route('browser-sessions.live-view', $session))
            ->missing('session.live_view_url')
            ->missing('session.provider_session_id'));
    $liveViewResponse = $this->get(route('browser-sessions.live-view', $session))
        ->assertOk()
        ->assertJson(['live_view_url' => 'https://browserbase.invalid/live/'.$session->id]);
    expect($liveViewResponse->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');

    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);
    expect($connection->refresh()->status)->toBe(RetailerConnectionStatus::Connected);
});

it('keeps an unfinished MFA or bot challenge in the owner-only login session', function () {
    $workspace = browserbaseCartWorkspace();
    config()->set('automation.connection_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->authenticated = false;
    $executor->authenticationFailureReason = 'Woolworths is waiting for MFA.';
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);

    expect(fn () => app(VerifyRetailerConnection::class)->handle($session, $workspace['user']))
        ->toThrow(ValidationException::class, 'waiting for MFA')
        ->and($session->refresh()->status)->toBe(BrowserSessionStatus::HumanControl)
        ->and($session->retailerConnection->refresh()->status)->toBe(RetailerConnectionStatus::ReauthenticationRequired);

    $executor->authenticationFailureReason = 'Woolworths presented bot detection.';
    $executor->botDetected = true;

    expect(fn () => app(VerifyRetailerConnection::class)->handle($session, $workspace['user']))
        ->toThrow(ValidationException::class, 'bot detection')
        ->and($session->refresh()->status)->toBe(BrowserSessionStatus::HumanControl);
});

it('marks a provider timeout as an error and releases the Context lease', function () {
    $workspace = browserbaseCartWorkspace();
    config()->set('automation.connection_enabled', true);
    $provider = app(BrowserSessionProvider::class);
    expect($provider)->toBeInstanceOf(FakeBrowserSessionProvider::class);
    $provider->createSessionFailure = new RuntimeException('Fake Browserbase timeout.');

    expect(fn () => app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']))
        ->toThrow(RuntimeException::class, 'Fake Browserbase timeout');

    $connection = RetailerConnection::query()->where('team_id', $workspace['team']->id)->sole();
    expect($connection->status)->toBe(RetailerConnectionStatus::Error)
        ->and($connection->lease_owner)->toBeNull()
        ->and($connection->lease_expires_at)->toBeNull()
        ->and($connection->browserSessions)->toHaveCount(0);
});

it('clears a Context revoked while opening an owner login so reconnect can recover', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    $provider = app(BrowserSessionProvider::class);
    expect($provider)->toBeInstanceOf(FakeBrowserSessionProvider::class);
    $provider->createSessionFailure = new RetailerContextRevokedException;

    expect(fn () => app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']))
        ->toThrow(RetailerContextRevokedException::class)
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::Revoked)
        ->and($connection->provider_context_id)->toBeNull();

    $replacement = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    expect($replacement->retailerConnection->provider_context_id)->toBe('fake-context-2')
        ->and($replacement->status)->toBe(BrowserSessionStatus::HumanControl);
});

it('expires a lost Live View and lets the owner open a replacement login session', function () {
    $workspace = browserbaseCartWorkspace();
    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    $provider = app(BrowserSessionProvider::class);
    expect($provider)->toBeInstanceOf(FakeBrowserSessionProvider::class);
    $provider->loseLiveView = true;

    $this->actingAs($workspace['user'])
        ->get(route('browser-sessions.live-view', $session))
        ->assertGone();

    expect($session->refresh()->status)->toBe(BrowserSessionStatus::Expired)
        ->and($session->retailerConnection->refresh()->lease_owner)->toBeNull();

    $provider->loseLiveView = false;
    $replacement = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    expect($replacement->id)->not->toBe($session->id)
        ->and($replacement->status)->toBe(BrowserSessionStatus::HumanControl);
});

it('freezes the exact current revision and makes run creation idempotent', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $key = (string) Str::uuid();

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        $key,
    );
    $replay = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        $key,
    );

    $workspace['item']->update(['name' => 'Changed after approval']);
    $workspace['list']->update(['revision' => 2]);

    expect($replay->id)->toBe($run->id)
        ->and($run->frozen_snapshot['revision'])->toBe(1)
        ->and($run->items()->sole()->requirement_snapshot['name'])->toBe('Full cream milk')
        ->and($run->refresh()->frozen_snapshot_checksum)->toBe(hash(
            'sha256',
            json_encode($run->frozen_snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ));
    Queue::assertPushed(AdvanceAutomationRunJob::class, 1);
});

it('rejects stale empty mismatched and unauthorised cart run inputs', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();

    $workspace['list']->update(['stale_at' => now(), 'stale_reason' => 'Plan changed']);
    expect(fn () => app(StartCartPreparation::class)->handle(
        $workspace['list']->refresh(),
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class, 'Refresh this shopping list');

    $workspace['list']->update(['stale_at' => null, 'stale_reason' => null]);
    $emptyRevision = ShoppingListRevision::factory()->create([
        'team_id' => $workspace['team']->id,
        'shopping_list_id' => $workspace['list']->id,
        'user_id' => $workspace['user']->id,
        'revision' => 2,
        'snapshot' => ['source_plan_revision' => 1, 'status' => 'draft', 'items' => []],
    ]);

    expect(fn () => app(StartCartPreparation::class)->handle(
        $workspace['list']->refresh(),
        $emptyRevision,
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class, 'current shopping-list revision');

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    expect(fn () => app(StartCartPreparation::class)->handle(
        $workspace['list']->refresh(),
        $workspace['revision'],
        $connection,
        $outsider,
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);
});

it('pauses for every non-empty cart and reconciles merge lines separately', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->cartLines = [[
        'external_product_id' => 'existing-bread',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
        'unit' => 'loaf',
        'unit_price' => 4,
        'total_price' => 4,
    ]];

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingExistingCartDecision)
        ->and($run->interventions()->where('type', AutomationInterventionType::ExistingCart->value)->count())->toBe(1)
        ->and($run->items()->sole()->status->value)->toBe('pending');

    $intervention = $run->interventions()->sole();
    app(ResolveAutomationIntervention::class)->handle($intervention, $workspace['user'], ['choice' => 'merge']);
    $run->refresh()->load('latestSnapshot.lines');

    expect($run->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($run->latestSnapshot)->not->toBeNull()
        ->and($run->latestSnapshot->lines->where('pre_existing', true)->pluck('product_name')->all())->toBe(['Wholemeal bread'])
        ->and($run->latestSnapshot->lines->where('pre_existing', false)->pluck('product_name')->all())->toContain('Full cream milk')
        ->and($run->latestSnapshot->cart_total)->toBe('8.50');
});

it('clears a non-empty cart only after an explicit replace decision', function () {
    $workspace = browserbaseCartWorkspace('Free range eggs');
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    $executor->cartLines = [[
        'external_product_id' => 'existing-bread',
        'product_name' => 'Wholemeal bread',
        'quantity' => 1,
        'total_price' => 4,
    ]];

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();
    expect(collect($executor->commands)->pluck('type'))->not->toContain('clear_cart');

    app(ResolveAutomationIntervention::class)->handle(
        $run->interventions()->sole(),
        $workspace['user'],
        ['choice' => 'replace'],
    );
    $run->refresh()->load('latestSnapshot.lines');

    expect($run->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and(collect($executor->commands)->pluck('type'))->toContain('clear_cart')
        ->and($run->latestSnapshot->lines->where('pre_existing', true))->toHaveCount(0)
        ->and($run->latestSnapshot->lines->pluck('product_name')->all())->toBe(['Free range eggs']);
});

it('stops for reauthentication and resumes only after the owner passes a fresh probe', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    $executor->authenticated = false;

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingReauthentication)
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::ReauthenticationRequired)
        ->and($run->items()->sole()->status->value)->toBe('pending');

    $loginSession = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    $executor->authenticated = true;
    app(VerifyRetailerConnection::class)->handle($loginSession, $workspace['user']);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($run->items()->sole()->status->value)->toBe('matched')
        ->and($run->interventions()->where('type', AutomationInterventionType::Reauthentication->value)->where('status', 'resolved')->exists())->toBeTrue();
});

it('replaces a revoked Browserbase Context through owner reauthentication', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $provider = app(BrowserSessionProvider::class);
    expect($provider)->toBeInstanceOf(FakeBrowserSessionProvider::class);
    $provider->createSessionFailure = new RetailerContextRevokedException;

    $checkpoint = app(ComputerUseEngine::class)->advance($run);

    expect($checkpoint->checkpoint)->toBe('awaiting_reauthentication')
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::AwaitingReauthentication)
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::Revoked)
        ->and($connection->provider_context_id)->toBeNull()
        ->and($run->steps()->where('action_type', 'browser_context_revoked')->exists())->toBeTrue();

    $loginSession = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    expect($loginSession->retailerConnection->provider_context_id)->toBe('fake-context-2');
    app(VerifyRetailerConnection::class)->handle($loginSession, $workspace['user']);

    expect($connection->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::Queued);
});

it('expires an old session and resumes through a fresh authenticated cart inspection', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $expiredSession = app(CreateBrowserSession::class)->handle(
        $connection,
        BrowserSessionPurpose::CartPreparation,
        $run,
    );
    $expiredSession->update(['expires_at' => now()->subMinute()]);

    $checkpoint = app(ComputerUseEngine::class)->advance($run->refresh());

    expect($checkpoint->checkpoint)->toBe('ready_for_review')
        ->and($expiredSession->refresh()->status)->toBe(BrowserSessionStatus::Expired)
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($run->browserSessions()->count())->toBe(2);
});

it('recovers from an unexpected Browserbase session loss before mutating the cart', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->loseNextSession = true;

    $lost = app(ComputerUseEngine::class)->advance($run);
    expect($lost->shouldContinue)->toBeTrue()
        ->and($lost->checkpoint)->toBe('browser_session_lost')
        ->and($run->browserSessions()->sole()->status)->toBe(BrowserSessionStatus::Expired)
        ->and($run->steps()->where('action_type', 'browser_session_lost')->exists())->toBeTrue();

    $recovered = app(ComputerUseEngine::class)->advance($run->refresh());
    expect($recovered->checkpoint)->toBe('ready_for_review')
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($executor->cartLines)->toHaveCount(1);
});

it('pauses without mutation when bot detection appears during cart inspection', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->cartBotDetected = true;

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingItemDecision)
        ->and($run->interventions()->sole()->type)->toBe(AutomationInterventionType::BotDetection)
        ->and($run->items()->sole()->status->value)->toBe('pending')
        ->and($executor->cartLines)->toHaveCount(0);
});

it('pauses when a verified cart line breaches the saved item price limit', function () {
    $workspace = browserbaseCartWorkspace();
    ProductPreference::query()->create([
        'team_id' => $workspace['team']->id,
        'identity_key' => hash('sha256', 'full-cream-milk-price'),
        'normalized_item_name' => 'full cream milk',
        'accept_substitutes' => true,
        'maximum_price' => 2,
    ]);
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingItemDecision)
        ->and($run->interventions()->sole()->type)->toBe(AutomationInterventionType::PriceLimit)
        ->and($run->items()->sole()->status->value)->toBe('awaiting_decision')
        ->and($run->latestSnapshot)->toBeNull();
});

it('requires an explicit decision for a substitution outside saved policy', function () {
    $workspace = browserbaseCartWorkspace();
    ProductPreference::query()->create([
        'team_id' => $workspace['team']->id,
        'identity_key' => hash('sha256', 'full-cream-milk-substitution'),
        'normalized_item_name' => 'full cream milk',
        'accept_substitutes' => false,
    ]);
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->preparedStatus = 'substituted';
    $executor->preparedProductName = 'Soy milk';

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingItemDecision)
        ->and($run->interventions()->sole()->type)->toBe(AutomationInterventionType::Substitution)
        ->and($run->interventions()->sole()->payload['product']['product_name'])->toBe('Soy milk')
        ->and($run->items()->sole()->status->value)->toBe('awaiting_decision');
});

it('classifies verified price and quantity changes in the final review', function (string $change, string $classification) {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);

    if ($change === 'price') {
        $executor->preparedUnitPrice = 6;
    } else {
        $executor->preparedQuantity = 2;
    }

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($run->latestSnapshot?->lines()->sole()->classification->value)->toBe($classification);
})->with([
    'price changed' => ['price', 'price_changed'],
    'quantity adjusted' => ['quantity', 'quantity_adjusted'],
]);

it('does not duplicate an already verified remote line after a safe retry', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    $executor->cartLines = [[
        'external_product_id' => 'remote-milk',
        'product_name' => 'Full cream milk',
        'quantity' => 1,
        'unit' => 'litre',
        'unit_price' => 3.5,
        'total_price' => 3.5,
    ]];

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();
    app(ResolveAutomationIntervention::class)->handle(
        $run->interventions()->sole(),
        $workspace['user'],
        ['choice' => 'merge'],
    );

    expect($executor->cartLines)->toHaveCount(1)
        ->and($executor->cartLines[0]['quantity'])->toBe(2.0)
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::ReadyForReview);
});

it('pauses and safely rebuilds an item removed before final reconciliation', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $executor = app(ComputerExecutor::class);
    expect($executor)->toBeInstanceOf(FakeComputerExecutor::class);
    $executor->removeLinesOnReconcile = true;

    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();

    expect($run->status)->toBe(AutomationRunStatus::AwaitingItemDecision)
        ->and($run->interventions()->sole()->type)->toBe(AutomationInterventionType::CartChanged)
        ->and($run->items()->sole()->status->value)->toBe('awaiting_decision');

    $executor->removeLinesOnReconcile = false;
    app(ResolveAutomationIntervention::class)->handle(
        $run->interventions()->sole(),
        $workspace['user'],
        ['choice' => 'retry'],
    );

    expect($run->refresh()->status)->toBe(AutomationRunStatus::ReadyForReview)
        ->and($executor->cartLines)->toHaveCount(1)
        ->and($run->latestSnapshot?->lines)->toHaveCount(1);
});

it('enforces team route isolation and connection-owner authentication policy', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Isolated family');

    $this->actingAs($outsider)
        ->get(route('automation-runs.status', $run))
        ->assertNotFound();

    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();
    $this->actingAs($member)
        ->post(route('retailer-connections.authenticate.start', $connection))
        ->assertForbidden();
});

it('prevents concurrent human and agent control of one Browserbase context', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $agentSession = app(CreateBrowserSession::class)->handle(
        $connection,
        BrowserSessionPurpose::CartPreparation,
        $run,
    );

    expect(fn () => app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']))
        ->toThrow(ValidationException::class, 'already in use')
        ->and($connection->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($agentSession->refresh()->ended_at)->toBeNull();
});

it('stops the model for owner-only manual takeover and reconciles before resuming', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $agentSession = app(CreateBrowserSession::class)->handle(
        $connection,
        BrowserSessionPurpose::CartPreparation,
        $run,
    );
    $takeoverSession = app(StartAutomationTakeover::class)->handle($run, $workspace['user']);

    expect($takeoverSession->id)->toBe($agentSession->id)
        ->and($takeoverSession->status)->toBe(BrowserSessionStatus::HumanControl)
        ->and($takeoverSession->purpose)->toBe(BrowserSessionPurpose::ManualTakeover)
        ->and($takeoverSession->recording_enabled)->toBeFalse()
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::AwaitingItemDecision)
        ->and($run->interventions()->sole()->type)->toBe(AutomationInterventionType::ManualTakeover);
    $checkpoint = app(ComputerUseEngine::class)->advance($run->refresh());
    expect($checkpoint->checkpoint)->toBe('manual_takeover')
        ->and($takeoverSession->refresh()->status)->toBe(BrowserSessionStatus::HumanControl);

    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    expect(fn () => app(FinishAutomationTakeover::class)->handle($takeoverSession, $member))
        ->toThrow(AuthorizationException::class);

    app(FinishAutomationTakeover::class)->handle($takeoverSession, $workspace['user']);

    expect($takeoverSession->refresh()->status)->toBe(BrowserSessionStatus::Closed)
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::Queued)
        ->and($run->interventions()->sole()->status->value)->toBe('resolved');
    // The original unique advancement job remains queued in this fake. In a
    // live worker it either resumes the queued run or releases uniqueness so
    // FinishAutomationTakeover can enqueue the continuation.
    Queue::assertPushed(AdvanceAutomationRunJob::class, 1);
});

it('denies cross-family access to every team-owned automation record', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    )->refresh();
    $run->load(['items', 'browserSessions', 'steps', 'latestSnapshot.lines']);
    $intervention = AutomationIntervention::factory()->create([
        'team_id' => $workspace['team']->id,
        'automation_run_id' => $run->id,
        'automation_run_item_id' => $run->items->firstOrFail()->id,
    ]);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other automation family');
    $records = collect([
        $connection,
        $run,
        $run->items->firstOrFail(),
        $run->browserSessions->firstOrFail(),
        $run->steps->firstOrFail(),
        $intervention,
        $run->latestSnapshot,
        $run->latestSnapshot?->lines->firstOrFail(),
    ])->filter();

    foreach ($records as $record) {
        expect($workspace['user']->can('view', $record))->toBeTrue()
            ->and($outsider->can('view', $record))->toBeFalse();
    }
});

it('deletes the provider context and cancels active work on disconnect', function () {
    $workspace = browserbaseCartWorkspace();
    $connection = connectedWoolworths($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Queue::fake();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    );
    $provider = app(BrowserSessionProvider::class);
    expect($provider)->toBeInstanceOf(FakeBrowserSessionProvider::class);

    app(DisconnectRetailerConnection::class)->handle($connection, $workspace['user']);

    expect($connection->refresh()->status)->toBe(RetailerConnectionStatus::Disconnected)
        ->and($connection->provider_context_id)->toBeNull()
        ->and($run->refresh()->status)->toBe(AutomationRunStatus::Cancelled)
        ->and($provider->contextsDeleted)->toBe(1);
});
