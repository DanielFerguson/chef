<?php

use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     owner: User,
 *     member: User,
 *     outsider: User,
 *     connection: RetailerConnection,
 *     run: RetailerOrderRun,
 * }
 */
function retailerOrderRunPolicyWorkspace(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($owner, 'Order family');
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
    ]);

    return compact('owner', 'member', 'outsider', 'connection', 'run');
}

it('allows team shoppers to view, select fulfilment, confirm, verify placement, and cancel order runs', function () {
    ['member' => $member, 'run' => $run] = retailerOrderRunPolicyWorkspace();

    expect($member->can('view', $run))->toBeTrue()
        ->and($member->can('selectFulfilment', $run))->toBeTrue()
        ->and($member->can('confirm', $run))->toBeTrue()
        ->and($member->can('verifyPlacement', $run))->toBeTrue()
        ->and($member->can('cancel', $run))->toBeTrue()
        ->and($member->can('update', $run))->toBeTrue();
});

it('denies non-members every retailer order run ability', function () {
    ['outsider' => $outsider, 'run' => $run] = retailerOrderRunPolicyWorkspace();

    expect($outsider->can('view', $run))->toBeFalse()
        ->and($outsider->can('selectFulfilment', $run))->toBeFalse()
        ->and($outsider->can('confirm', $run))->toBeFalse()
        ->and($outsider->can('verifyPlacement', $run))->toBeFalse()
        ->and($outsider->can('cancel', $run))->toBeFalse()
        ->and($outsider->can('update', $run))->toBeFalse();
});

it('keeps retailer connection authenticate owner-only for non-owner shoppers', function () {
    ['owner' => $owner, 'member' => $member, 'outsider' => $outsider, 'connection' => $connection] = retailerOrderRunPolicyWorkspace();

    expect($owner->can('authenticate', $connection))->toBeTrue()
        ->and($owner->can('useForAutomation', $connection))->toBeTrue()
        ->and($member->can('authenticate', $connection))->toBeFalse()
        ->and($member->can('useForAutomation', $connection))->toBeFalse()
        ->and($outsider->can('authenticate', $connection))->toBeFalse()
        ->and($outsider->can('useForAutomation', $connection))->toBeFalse();
});
