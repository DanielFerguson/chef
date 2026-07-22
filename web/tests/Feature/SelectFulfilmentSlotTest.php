<?php

use App\Actions\Retailer\AdvanceRetailerOrderRun;
use App\Actions\Retailer\SelectFulfilmentSlot;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\RetailerOrderRunStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\User;
use App\Retailer\Contracts\RetailerBrowser;
use App\Retailer\Data\FulfilmentOptions;
use App\Retailer\Data\SlotSelection;
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
 *     browser: FakeRetailerBrowser,
 *     slot: array<string, mixed>,
 * }
 */
function fulfilmentSelectionFixture(array $runOverrides = []): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Fulfilment family');
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
        'fulfilment_method' => 'delivery',
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

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::CartReady,
        'fulfilment_type' => 'delivery',
        'cart_checksum' => hash('sha256', 'cart'),
        'expires_at' => now()->addHour(),
        ...$runOverrides,
    ]);

    $browser = app(RetailerBrowser::class);
    expect($browser)->toBeInstanceOf(FakeRetailerBrowser::class);

    return compact('owner', 'member', 'outsider', 'run', 'browser', 'slot');
}

it('advances CartReady into scraped fulfilment options awaiting household selection', function () {
    $fixture = fulfilmentSelectionFixture();
    $expiresAt = now()->addMinutes(30);

    $fixture['browser']->queue('extractFulfilmentOptions', new FulfilmentOptions(
        type: 'delivery',
        slots: [$fixture['slot']],
        expiresAt: $expiresAt->toImmutable(),
    ));

    $result = app(AdvanceRetailerOrderRun::class)->handle($fixture['run']);

    $run = $fixture['run']->refresh();

    expect($result->shouldContinue)->toBeFalse()
        ->and($run->status)->toBe(RetailerOrderRunStatus::AwaitingFulfilmentSelection)
        ->and($run->fulfilment_type)->toBe('delivery')
        ->and($run->fulfilment_options)->toMatchArray([
            'type' => 'delivery',
            'slots' => [$fixture['slot']],
        ])
        ->and($run->fulfilment_options_expires_at?->timestamp)->toBe($expiresAt->timestamp)
        ->and($fixture['browser']->calls)->toHaveCount(1)
        ->and($fixture['browser']->calls[0])->toMatchArray([
            'method' => 'extractFulfilmentOptions',
            'fulfilment_type' => 'delivery',
        ]);
});

it('copies shopping-list fulfilment intent onto the run before scraping', function () {
    $fixture = fulfilmentSelectionFixture([
        'fulfilment_type' => null,
    ]);
    $fixture['run']->shoppingList->update(['fulfilment_method' => 'pickup']);

    $fixture['browser']->queue('extractFulfilmentOptions', new FulfilmentOptions(
        type: 'pickup',
        slots: [[
            'id' => 'pickup-1',
            'label' => 'Today 4–5pm',
        ]],
        expiresAt: now()->addMinutes(20)->toImmutable(),
    ));

    app(AdvanceRetailerOrderRun::class)->handle($fixture['run']->refresh());

    expect($fixture['run']->refresh()->fulfilment_type)->toBe('pickup')
        ->and($fixture['run']->status)->toBe(RetailerOrderRunStatus::AwaitingFulfilmentSelection)
        ->and($fixture['browser']->calls[0]['fulfilment_type'])->toBe('pickup');
});

it('selects a scraped slot and advances to awaiting order confirmation', function () {
    $slot = [
        'id' => 'slot-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];

    $fixture = fulfilmentSelectionFixture([
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
        'fulfilment_options' => [
            'type' => 'delivery',
            'slots' => [$slot],
        ],
        'fulfilment_options_expires_at' => now()->addMinutes(15),
    ]);

    $fixture['browser']->queue('applyFulfilmentSlot', new ToolResult(ok: true, payload: [
        'slot_id' => 'slot-1',
    ]));

    $run = app(SelectFulfilmentSlot::class)->handle(
        $fixture['run'],
        $fixture['member'],
        'slot-1',
        'delivery',
    );

    expect($run->status)->toBe(RetailerOrderRunStatus::AwaitingOrderConfirmation)
        ->and($run->fulfilment_type)->toBe('delivery')
        ->and($run->selected_slot)->toMatchArray($slot)
        ->and($fixture['browser']->calls)->toHaveCount(1)
        ->and($fixture['browser']->calls[0]['method'])->toBe('applyFulfilmentSlot')
        ->and($fixture['browser']->calls[0]['slot'])->toBeInstanceOf(SlotSelection::class)
        ->and($fixture['browser']->calls[0]['slot']->id)->toBe('slot-1');
});

it('rejects an expired fulfilment snapshot, refetches options, and re-queues advance', function () {
    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    $slot = [
        'id' => 'slot-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];

    $fixture = fulfilmentSelectionFixture([
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
        'fulfilment_options' => [
            'type' => 'delivery',
            'slots' => [$slot],
        ],
        'fulfilment_options_expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(SelectFulfilmentSlot::class)->handle(
        $fixture['run'],
        $fixture['member'],
        'slot-1',
        'delivery',
    ))->toThrow(ValidationException::class, 'Those fulfilment options have expired');

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::FetchingFulfilmentOptions)
        ->and($fixture['run']->selected_slot)->toBeNull()
        ->and($fixture['browser']->calls)->toBe([]);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $job) use ($fixture): bool {
        return $job->retailerOrderRunId === $fixture['run']->id;
    });
});

it('denies fulfilment selection across teams', function () {
    $slot = [
        'id' => 'slot-1',
        'label' => 'Tomorrow 6–8pm',
        'starts_at' => '2026-07-23T18:00:00+10:00',
        'ends_at' => '2026-07-23T20:00:00+10:00',
        'fee' => 0.0,
    ];

    $fixture = fulfilmentSelectionFixture([
        'status' => RetailerOrderRunStatus::AwaitingFulfilmentSelection,
        'fulfilment_options' => [
            'type' => 'delivery',
            'slots' => [$slot],
        ],
        'fulfilment_options_expires_at' => now()->addMinutes(15),
    ]);

    expect(fn () => app(SelectFulfilmentSlot::class)->handle(
        $fixture['run'],
        $fixture['outsider'],
        'slot-1',
        'delivery',
    ))->toThrow(AuthorizationException::class);

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::AwaitingFulfilmentSelection)
        ->and($fixture['run']->selected_slot)->toBeNull()
        ->and($fixture['browser']->calls)->toBe([]);
});
