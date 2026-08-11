<?php

use App\Actions\Baskets\RecordBasketSnapshot;
use App\Actions\Baskets\RequestBasketRestoration;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerSelectionMethod;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\RestoreBasketJob;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Notifications\BasketRunStatusNotification;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/**
 * @return array{
 *     user: User,
 *     team: Team,
 *     connection: RetailerConnection,
 *     run: BasketRun
 * }
 */
function basketApiWorkspace(BasketRunStatus $status = BasketRunStatus::Ready): array
{
    config()->set('retailer.features.experience', true);
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Basket API family');
    $mealPlan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'api_context',
        'context_lookup_hash' => hash('sha256', 'api_context'),
        'authenticated_at' => now(),
        'last_verified_at' => now(),
    ]);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', "api-grocery-{$mealPlan->id}"),
        'recipe_fingerprint' => hash('sha256', "api-recipes-{$mealPlan->id}"),
        'built_at' => now(),
    ]);
    $requirement = $groceryPlan->requirements()->create([
        'team_id' => $team->id,
        'status' => GroceryRequirementStatus::Selected,
        'display_name' => 'Penne pasta',
        'normalized_name' => 'pasta',
        'normalized_form' => 'penne',
        'quantity' => 800,
        'unit' => 'g',
        'quantity_unknown' => false,
        'fingerprint' => hash('sha256', "api-requirement-{$mealPlan->id}"),
        'search_queries' => ['penne pasta', 'pasta'],
        'applicable_constraints' => [],
    ]);
    $candidate = $requirement->candidates()->create([
        'team_id' => $team->id,
        'provider' => RetailerProvider::Coles,
        'sku' => '3329035',
        'title' => 'Coles Penne Pasta 500g',
        'brand' => 'Coles',
        'semantic_key' => 'penne pasta',
        'origin_host' => 'www.coles.com.au',
        'product_path' => '/product/coles-penne-pasta-3329035',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => 150,
        'available' => true,
        'status' => RetailerCandidateStatus::Eligible,
        'rejection_codes' => [],
        'label_evidence' => [],
        'fingerprint' => hash('sha256', "api-candidate-{$mealPlan->id}"),
        'captured_at' => now(),
    ]);
    $selection = $requirement->selection()->create([
        'team_id' => $team->id,
        'retailer_product_candidate_id' => $candidate->id,
        'method' => RetailerSelectionMethod::Deterministic,
        'confidence' => 1,
        'low_confidence' => false,
        'reasoning' => 'Two 500 g packs cover 800 g with the balanced price and waste policy.',
        'pack_count' => 2,
        'required_quantity' => 800,
        'total_quantity' => 1000,
        'waste_quantity' => 200,
        'total_price_cents' => 300,
        'selection_checksum' => hash('sha256', "api-selection-{$mealPlan->id}"),
        'revalidation_checksum' => $candidate->fingerprint,
        'selected_at' => now(),
        'revalidated_at' => now(),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection->id,
        'requested_by_user_id' => $user->id,
        'status' => $status,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', "api-run-{$mealPlan->id}"),
        'replaced_line_count' => 1,
        'chef_subtotal_cents' => 300,
        'retailer_total_cents' => 300,
        'basket_captured_at' => now(),
    ]);
    $run->items()->create([
        'team_id' => $team->id,
        'grocery_requirement_id' => $requirement->id,
        'retailer_product_selection_id' => $selection->id,
        'sku' => $candidate->sku,
        'product_title' => $candidate->title,
        'absolute_quantity' => 2,
        'unit_price_cents' => 150,
        'line_price_cents' => 300,
        'pack_reasoning' => $selection->reasoning,
        'verification_checksum' => hash('sha256', 'api-item'),
        'verified_at' => now(),
    ]);
    app(RecordBasketSnapshot::class)->handle(
        $run,
        BasketSnapshotKind::Baseline,
        [
            'lines' => [[
                'sku' => 'old-apple',
                'title' => 'Apples',
                'absolute_quantity' => 1,
                'unit_price_cents' => 100,
                'line_price_cents' => 100,
            ]],
            'retailer_total_cents' => 100,
        ],
    );
    app(RecordBasketSnapshot::class)->handle(
        $run,
        BasketSnapshotKind::Final,
        [
            'lines' => [[
                'sku' => $candidate->sku,
                'title' => $candidate->title,
                'absolute_quantity' => 2,
                'unit_price_cents' => 150,
                'line_price_cents' => 300,
            ]],
            'retailer_total_cents' => 300,
        ],
    );

    return compact('user', 'team', 'connection', 'run');
}

it('returns the same rich basket contract to web and API clients', function () {
    $workspace = basketApiWorkspace();

    $this->actingAs($workspace['user'])
        ->get("/basket-runs/{$workspace['run']->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('baskets/show')
            ->where('basket.id', $workspace['run']->id)
            ->where('basket.confirmed', true)
            ->where('basket.items.0.product.sku', '3329035')
            ->where('basket.can.restore', true));

    Sanctum::actingAs($workspace['user']);
    $this->getJson("/api/v1/basket-runs/{$workspace['run']->id}")
        ->assertOk()
        ->assertJsonPath('basket.status', BasketRunStatus::Ready->value)
        ->assertJsonPath('basket.confirmed', true)
        ->assertJsonPath('basket.uncertain', false)
        ->assertJsonPath('basket.items.0.product.absolute_quantity', 2)
        ->assertJsonPath('basket.items.0.reasoning', 'Two 500 g packs cover 800 g with the balanced price and waste policy.')
        ->assertJsonPath('basket.previous_line_count', 1)
        ->assertJsonPath('basket.totals.chef_subtotal_cents', 300)
        ->assertJsonPath('basket.totals.retailer_total_cents', 300)
        ->assertJsonPath('basket.totals.price_notice', 'Prices are time-sensitive estimates until checkout.');
});

it('allows household members to review Chef state but reserves retailer control for the account owner', function () {
    Queue::fake();
    $workspace = basketApiWorkspace();
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();

    Sanctum::actingAs($member);
    $this->getJson("/api/v1/basket-runs/{$workspace['run']->id}")
        ->assertOk()
        ->assertJsonPath('basket.connection.owned_by_current_user', false);
    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/restore")
        ->assertForbidden();
    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/review-session")
        ->assertForbidden();

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other basket family');
    Sanctum::actingAs($outsider);
    $this->getJson("/api/v1/basket-runs/{$workspace['run']->id}")
        ->assertNotFound();
});

it('retries an untouched failed basket only for the Coles account owner', function () {
    Queue::fake();
    config()->set('retailer.features.discovery', true);
    $workspace = basketApiWorkspace(BasketRunStatus::Failed);
    $workspace['run']->update([
        'failure_code' => 'worker_timeout',
        'failure_message' => 'The recorded worker timed out.',
        'basket_captured_at' => null,
    ]);
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();

    Sanctum::actingAs($member);
    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/retry")
        ->assertForbidden();

    Sanctum::actingAs($workspace['user']);
    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/retry")
        ->assertOk()
        ->assertJsonPath('basket_run.status', BasketRunStatus::DiscoveringProducts->value);

    $run = $workspace['run']->refresh();
    $requirement = $run->groceryPlan->requirements()->sole();

    expect($run->failure_code)->toBeNull()
        ->and($run->failure_message)->toBeNull()
        ->and($requirement->status)->toBe(GroceryRequirementStatus::Pending)
        ->and($requirement->candidates()->sole()->status)->toBe(RetailerCandidateStatus::Stale);
    Queue::assertPushed(
        DiscoverRetailerProductsJob::class,
        fn (DiscoverRetailerProductsJob $job): bool => $job->basketRunId === $run->id,
    );
});

it('refuses to replay a basket retry after destructive mutation has started', function () {
    Queue::fake();
    $workspace = basketApiWorkspace(BasketRunStatus::Failed);
    $workspace['run']->update(['basket_cleared_at' => now()]);
    Sanctum::actingAs($workspace['user']);

    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/retry")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('basket');

    Queue::assertNothingPushed();
});

it('refuses to retry while Coles Live View is active or opening', function (array $sessionState) {
    Queue::fake();
    config()->set('retailer.features.discovery', true);
    $workspace = basketApiWorkspace(BasketRunStatus::Failed);
    $workspace['run']->update(['basket_captured_at' => null]);
    $workspace['connection']->update($sessionState);
    Sanctum::actingAs($workspace['user']);

    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/retry")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('basket');

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Failed);
    Queue::assertNothingPushed();
})->with([
    'active session' => [[
        'active_session_id' => 'live_session',
        'active_session_claim_token' => 'live_claim',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(2),
    ]],
    'opening session' => [[
        'active_session_claim_token' => 'opening_claim',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(2),
    ]],
]);

it('refuses to restore while Coles Live View is active or opening', function (array $sessionState) {
    Queue::fake();
    $workspace = basketApiWorkspace();
    $workspace['connection']->update($sessionState);
    Sanctum::actingAs($workspace['user']);

    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/restore")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('basket');

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Ready);
    Queue::assertNothingPushed();
})->with([
    'active session' => [[
        'active_session_id' => 'live_session',
        'active_session_claim_token' => 'live_claim',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(2),
    ]],
    'opening session' => [[
        'active_session_claim_token' => 'opening_claim',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(2),
    ]],
]);

it('rechecks the current basket state before requesting restoration', function () {
    Queue::fake();
    $workspace = basketApiWorkspace();
    $staleRun = $workspace['run']->fresh();
    BasketRun::query()->whereKey($workspace['run'])->update([
        'status' => BasketRunStatus::DiscoveringProducts->value,
    ]);

    expect(fn () => app(RequestBasketRestoration::class)->handle($staleRun, $workspace['user']))
        ->toThrow(ValidationException::class)
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::DiscoveringProducts);
    Queue::assertNothingPushed();
});

it('refuses to open review Live View while basket automation is active', function () {
    $workspace = basketApiWorkspace(BasketRunStatus::DiscoveringProducts);
    Sanctum::actingAs($workspace['user']);

    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/review-session")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('basket');

    expect($workspace['connection']->refresh()->active_session_claim_token)->toBeNull();
});

it('queues owner-authorised restoration and returns a short-lived review capability only after automation stops', function () {
    Queue::fake();
    $workspace = basketApiWorkspace();
    Sanctum::actingAs($workspace['user']);

    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/restore")
        ->assertOk()
        ->assertJsonPath('basket_run.status', BasketRunStatus::Restoring->value);
    Queue::assertPushed(RestoreBasketJob::class, fn (RestoreBasketJob $job): bool => $job->basketRunId === $workspace['run']->id);

    $workspace['run']->refresh()->update(['status' => BasketRunStatus::Ready]);
    $this->postJson("/api/v1/basket-runs/{$workspace['run']->id}/review-session")
        ->assertOk()
        ->assertJsonPath('session.live_view_url', fn ($value): bool => is_string($value)
            && str_starts_with($value, 'https://live.example.test/'));

    expect($workspace['connection']->refresh()->active_session_purpose)->toBe('review');
});

it('persists only in-app status notifications and supports API acknowledgement', function () {
    $workspace = basketApiWorkspace(BasketRunStatus::ReplacingBasket);
    $notificationManager = app(ChannelManager::class);
    Notification::fake();

    $workspace['run']->update(['status' => BasketRunStatus::Ready]);

    Notification::assertSentTo(
        $workspace['user'],
        BasketRunStatusNotification::class,
        fn (BasketRunStatusNotification $notification): bool => $notification->basketRun->is($workspace['run']),
    );

    Notification::swap($notificationManager);
    $workspace['user']->notify(new BasketRunStatusNotification($workspace['run']->refresh()));
    $notification = $workspace['user']->notifications()->sole();

    expect($notification->data['status'])->toBe(BasketRunStatus::Ready->value)
        ->and($notification->data['basket_run_id'])->toBe($workspace['run']->id)
        ->and($notification->read_at)->toBeNull();

    $this->actingAs($workspace['user'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.unread_count', 1)
            ->where('notifications.items.0.basket_run_id', $workspace['run']->id));

    Sanctum::actingAs($workspace['user']);
    $this->getJson('/api/v1/session')
        ->assertOk()
        ->assertJsonPath('notifications.unread_count', 1)
        ->assertJsonPath('notifications.items.0.basket_run_id', $workspace['run']->id);

    app(CreateTeamForUser::class)->handle($workspace['user'], 'Other notification family');
    $this->putJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();
    expect($notification->refresh()->read_at)->toBeNull();

    $workspace['user']->forceFill(['current_team_id' => $workspace['team']->id])->save();
    $this->putJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('read_at', fn ($value): bool => is_string($value));

    expect($notification->refresh()->read_at)->not->toBeNull();
});

it('counts every unread notification while limiting each notification preview to ten items', function () {
    $workspace = basketApiWorkspace(BasketRunStatus::Ready);

    foreach (range(1, 12) as $notification) {
        $workspace['user']->notify(new BasketRunStatusNotification($workspace['run']->refresh()));
    }

    $this->actingAs($workspace['user'])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.unread_count', 12)
            ->has('notifications.items', 10));

    Sanctum::actingAs($workspace['user']);
    $this->getJson('/api/v1/session')
        ->assertOk()
        ->assertJsonPath('notifications.unread_count', 12)
        ->assertJsonCount(10, 'notifications.items');
});
