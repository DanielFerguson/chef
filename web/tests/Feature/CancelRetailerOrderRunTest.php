<?php

use App\Actions\Retailer\CancelRetailerOrderRun;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BrowserSessionPurpose;
use App\Enums\BrowserSessionStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\BrowserSession;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{owner: User, member: User, outsider: User, run: RetailerOrderRun}
 */
function cancelOrderRunFixture(array $runOverrides = []): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Cancel order family');
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
    ]);

    $run = RetailerOrderRun::factory()->create([
        'team_id' => $team->id,
        'shopping_list_id' => $list->id,
        'retailer_connection_id' => $connection->id,
        'started_by_user_id' => $owner->id,
        'status' => RetailerOrderRunStatus::AwaitingCartDecision,
        ...$runOverrides,
    ]);

    return compact('owner', 'member', 'outsider', 'run');
}

it('cancels an active retailer order run to a terminal Cancelled status', function () {
    $fixture = cancelOrderRunFixture();

    $run = app(CancelRetailerOrderRun::class)->handle($fixture['run'], $fixture['member']);

    expect($run->status)->toBe(RetailerOrderRunStatus::Cancelled)
        ->and($run->status->isTerminal())->toBeTrue();
});

it('closes open cart-preparation browser sessions when cancelling', function () {
    $fixture = cancelOrderRunFixture([
        'status' => RetailerOrderRunStatus::PreparingCart,
    ]);

    $session = BrowserSession::factory()->create([
        'team_id' => $fixture['run']->team_id,
        'retailer_connection_id' => $fixture['run']->retailer_connection_id,
        'purpose' => BrowserSessionPurpose::CartPreparation,
        'status' => BrowserSessionStatus::AgentControl,
        'ended_at' => null,
        'expires_at' => now()->addHour(),
    ]);

    app(CancelRetailerOrderRun::class)->handle($fixture['run'], $fixture['member']);

    expect($fixture['run']->refresh()->status)->toBe(RetailerOrderRunStatus::Cancelled)
        ->and($session->refresh()->status)->toBe(BrowserSessionStatus::Closed)
        ->and($session->ended_at)->not->toBeNull();
});

it('rejects cancellation from outsiders and is idempotent when already terminal', function () {
    $fixture = cancelOrderRunFixture();

    expect(fn () => app(CancelRetailerOrderRun::class)->handle($fixture['run'], $fixture['outsider']))
        ->toThrow(AuthorizationException::class);

    $fixture['run']->update(['status' => RetailerOrderRunStatus::Cancelled]);

    $run = app(CancelRetailerOrderRun::class)->handle($fixture['run']->refresh(), $fixture['member']);

    expect($run->status)->toBe(RetailerOrderRunStatus::Cancelled);
});

it('refuses cancel while submitting or awaiting placement verification', function () {
    foreach ([
        RetailerOrderRunStatus::SubmittingOrder,
        RetailerOrderRunStatus::AwaitingPlacementVerification,
    ] as $status) {
        $fixture = cancelOrderRunFixture(['status' => $status]);

        expect(fn () => app(CancelRetailerOrderRun::class)->handle($fixture['run'], $fixture['member']))
            ->toThrow(ValidationException::class);

        expect($fixture['run']->refresh()->status)->toBe($status);
    }
});
