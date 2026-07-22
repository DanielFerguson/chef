<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailer\ConfirmRetailerOrder;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\ExistingCartDecision;
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
use App\Retailer\Data\ToolResult;
use App\Retailer\RetailerOrderRunView;
use App\Retailer\Testing\FakeRetailerBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     owner: User,
 *     member: User,
 *     outsider: User,
 *     plan: MealPlan,
 *     list: ShoppingList,
 *     connection: RetailerConnection,
 *     slot: array<string, mixed>,
 *     cartChecksum: string,
 * }
 */
function retailerOrderRunHttpFixture(array $runOverrides = []): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'HTTP order family');
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($team, $member);
    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');

    $plan = app(StartMealPlan::class)->handle($team, $owner, today(), today()->addDays(2), 'Order plan');
    $list = ShoppingList::factory()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $owner->id,
        'fulfilment_method' => 'delivery',
        'revision' => 1,
        'generation_status' => 'ready',
    ]);
    $connection = RetailerConnection::factory()->create([
        'team_id' => $team->id,
        'owner_user_id' => $owner->id,
    ]);

    $slot = [
        'id' => 'slot-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];
    $cartChecksum = hash('sha256', 'http-cart');

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
        'fulfilment_type' => 'delivery',
        'fulfilment_options' => [
            'type' => 'delivery',
            'slots' => [$slot],
        ],
        'fulfilment_options_expires_at' => now()->addMinutes(20),
        'cart_checksum' => $cartChecksum,
        'expires_at' => now()->addHour(),
        ...$runOverrides,
    ]);

    RetailerOrderRunItem::factory()->create([
        'team_id' => $team->id,
        'retailer_order_run_id' => $run->id,
        'shopping_list_item_id' => null,
        'position' => 1,
        'status' => RetailerOrderRunItemStatus::Matched,
        'requirement_snapshot' => [
            'name' => 'Full Cream Milk 2L',
            'quantity' => 1,
            'unit' => 'each',
        ],
        'matched_product' => [
            'external_id' => '123456',
            'product_name' => 'Full Cream Milk 2L',
            'quantity' => 1,
        ],
        'resolved_at' => now(),
    ]);

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    return compact('owner', 'member', 'outsider', 'plan', 'list', 'connection', 'slot', 'cartChecksum') + [
        'run' => $run->refresh(),
        'browser' => $browser,
        'team' => $team,
    ];
}

it('selects a fulfilment slot over HTTP and redirects back to shopping', function () {
    $fixture = retailerOrderRunHttpFixture();
    $fixture['browser']->queue('applyFulfilmentSlot', new ToolResult(ok: true, payload: [
        'slot_id' => 'slot-1',
    ]));

    $this->actingAs($fixture['member'])
        ->post(route('retailer-order-runs.fulfilment.select', $fixture['run']), [
            'slot_id' => 'slot-1',
            'fulfilment_type' => 'delivery',
        ])
        ->assertRedirect(route('meal-plans.shopping.show', $fixture['plan']));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingOrderConfirmation)
        ->and($fixture['run']->selected_slot)->toMatchArray($fixture['slot']);
});

it('confirms a retailer order over HTTP and dispatches advance', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
        'selected_slot' => [
            'id' => 'slot-1',
            'label' => 'Tomorrow 6–8pm',
            'starts_at' => '2026-07-23T18:00:00+10:00',
            'ends_at' => '2026-07-23T20:00:00+10:00',
            'fee' => 0.0,
        ],
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
    ]);

    $this->actingAs($fixture['member'])
        ->post(route('retailer-order-runs.confirm', $fixture['run']))
        ->assertRedirect(route('meal-plans.shopping.show', $fixture['plan']));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::SubmittingOrder)
        ->and($fixture['run']->confirmation_fingerprint)->not->toBeNull();

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class);
});

it('verifies placement over HTTP with a retailer order reference', function () {
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingPlacementVerification,
        'selected_slot' => [
            'id' => 'slot-1',
            'label' => 'Tomorrow 6–8pm',
        ],
        'confirmation' => [
            'user_id' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'fingerprint' => 'fp',
        ],
        'confirmation_fingerprint' => 'fp',
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
    ]);

    $this->actingAs($fixture['member'])
        ->post(route('retailer-order-runs.verify', $fixture['run']), [
            'retailer_order_reference' => 'WW-HTTP-1',
        ])
        ->assertRedirect(route('meal-plans.shopping.show', $fixture['plan']));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::Placed)
        ->and($fixture['run']->retailer_order_reference)->toBe('WW-HTTP-1');
});

it('cancels a retailer order run over HTTP', function () {
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingCartDecision,
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
        'cart_checksum' => null,
    ]);

    $this->actingAs($fixture['member'])
        ->delete(route('retailer-order-runs.destroy', $fixture['run']))
        ->assertRedirect(route('meal-plans.shopping.show', $fixture['plan']));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::Cancelled);
});

it('resolves an existing cart decision over HTTP', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingCartDecision,
        'fulfilment_options' => null,
        'fulfilment_options_expires_at' => null,
        'cart_checksum' => null,
    ]);

    $this->actingAs($fixture['member'])
        ->put(route('retailer-order-runs.cart-decision.update', $fixture['run']), [
            'choice' => ExistingCartDecision::Merge->value,
        ])
        ->assertRedirect(route('meal-plans.shopping.show', $fixture['plan']));

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($fixture['run']->existing_cart_decision)->toBe(ExistingCartDecision::Merge);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class);
});

it('isolates retailer order run routes from other households', function () {
    $fixture = retailerOrderRunHttpFixture();

    $this->actingAs($fixture['outsider'])
        ->post(route('retailer-order-runs.fulfilment.select', $fixture['run']), [
            'slot_id' => 'slot-1',
            'fulfilment_type' => 'delivery',
        ])
        ->assertNotFound();

    $this->actingAs($fixture['outsider'])
        ->post(route('retailer-order-runs.confirm', $fixture['run']))
        ->assertNotFound();

    $this->actingAs($fixture['outsider'])
        ->post(route('retailer-order-runs.verify', $fixture['run']), [
            'acknowledged_placed' => true,
        ])
        ->assertNotFound();

    $this->actingAs($fixture['outsider'])
        ->delete(route('retailer-order-runs.destroy', $fixture['run']))
        ->assertNotFound();

    $this->actingAs($fixture['outsider'])
        ->put(route('retailer-order-runs.cart-decision.update', $fixture['run']), [
            'choice' => 'merge',
        ])
        ->assertNotFound();
});

it('exposes an active retailer order run on the shopping show Inertia payload', function () {
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
        'selected_slot' => [
            'id' => 'slot-1',
            'label' => 'Tomorrow 6–8pm',
            'starts_at' => '2026-07-23T18:00:00+10:00',
            'ends_at' => '2026-07-23T20:00:00+10:00',
            'fee' => 0.0,
        ],
    ]);

    $this->withoutVite();
    $this->actingAs($fixture['member'])
        ->get(route('meal-plans.shopping.show', $fixture['plan']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shopping/show')
            ->where('workspace.retailer_order_run.id', $fixture['run']->id)
            ->where('workspace.retailer_order_run.status', RetailerOrderRunStatus::AwaitingOrderConfirmation->value)
            ->where('workspace.retailer_order_run.cart_decision_needed', false)
            ->where('workspace.retailer_order_run.placement_verification_needed', false)
            ->where('workspace.retailer_order_run.can_open_woolworths_cart', false)
            ->where('workspace.retailer_order_run.open_woolworths_cart_url', null)
            ->where('workspace.retailer_order_run.confirmation.consequence', fn ($value) => is_string($value)
                && str_contains(mb_strtolower($value), 'default card on file'))
            ->has('workspace.retailer_order_run.items', 1)
            ->where('workspace.retailer_order_run.selected_slot.id', 'slot-1'));
});

it('builds a retailer order run view without a primary self-checkout CTA while confirm is available', function () {
    $fixture = retailerOrderRunHttpFixture([
        'status' => RetailerOrderRunStatus::AwaitingOrderConfirmation,
        'selected_slot' => [
            'id' => 'slot-1',
            'label' => 'Tomorrow 6–8pm',
        ],
    ]);

    $payload = app(RetailerOrderRunView::class)->make($fixture['run']);

    expect($payload['status'])->toBe(RetailerOrderRunStatus::AwaitingOrderConfirmation->value)
        ->and($payload['can_open_woolworths_cart'])->toBeFalse()
        ->and($payload['open_woolworths_cart_url'])->toBeNull()
        ->and($payload['confirmation'])->toMatchArray(
            ConfirmRetailerOrder::confirmationPayload($fixture['run']),
        )
        ->and($payload['cart_decision_needed'])->toBeFalse()
        ->and($payload['items'])->toHaveCount(1)
        ->and($payload['items'][0]['name'])->toBe('Full Cream Milk 2L');
});
