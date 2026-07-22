<?php

use App\Actions\Automation\ContinueApprovedShopping;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\MealPlans\MealPlanShoppingApprovalContext;
use App\Actions\Retailer\StartRetailerOrderRun;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\CartProductPlanStatus;
use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Enums\ShoppingListGenerationStatus;
use App\Jobs\AdvanceRetailerOrderRunJob;
use App\Models\AutomationRun;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\RetailerOrderRun;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListRevision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array{user: User, team: Team, plan: MealPlan, list: ShoppingList, item: ShoppingListItem, revision: ShoppingListRevision} */
function retailerOrderRunWorkspace(string $itemName = 'Full cream milk'): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Order run family');
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
        'generation_status' => ShoppingListGenerationStatus::Ready,
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

function connectedWoolworthsForOrderRun(array $workspace): RetailerConnection
{
    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($workspace['team'], $workspace['user']);
    app(VerifyRetailerConnection::class)->handle($session, $workspace['user']);

    return $session->retailerConnection->refresh();
}

it('freezes a ready product plan into a PreparingCart retailer order run and dispatches advance', function () {
    $workspace = retailerOrderRunWorkspace();
    $connection = connectedWoolworthsForOrderRun($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    $run = app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
        safetyAcknowledged: true,
        productPlanReviewed: true,
    );

    expect($run)->toBeInstanceOf(RetailerOrderRun::class)
        ->and($run->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and($run->cart_product_plan_id)->toBeInt()
        ->and($run->shopping_list_revision_id)->toBe($workspace['revision']->id)
        ->and($run->retailer_connection_id)->toBe($connection->id)
        ->and($run->started_by_user_id)->toBe($workspace['user']->id)
        ->and($run->expires_at)->not->toBeNull()
        ->and($run->items)->toHaveCount(1)
        ->and($run->items->sole()->status)->toBe(RetailerOrderRunItemStatus::Pending)
        ->and($run->items->sole()->requirement_snapshot['name'])->toBe('Full cream milk')
        ->and($run->items->sole()->requirement_snapshot['product_match'])->not->toBeNull()
        ->and($run->cartProductPlan->refresh()->status)->toBe(CartProductPlanStatus::Frozen);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class, function (AdvanceRetailerOrderRunJob $job) use ($run): bool {
        return $job->retailerOrderRunId === $run->id;
    });
});

it('is idempotent when an active run already exists for the same revision and plan', function () {
    $workspace = retailerOrderRunWorkspace();
    $connection = connectedWoolworthsForOrderRun($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Bus::fake([AdvanceRetailerOrderRunJob::class]);
    $key = (string) Str::uuid();

    $run = app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        $key,
        safetyAcknowledged: true,
        productPlanReviewed: true,
    );
    $replay = app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        $key,
        safetyAcknowledged: true,
        productPlanReviewed: true,
    );

    expect($replay->id)->toBe($run->id)
        ->and(RetailerOrderRun::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(AdvanceRetailerOrderRunJob::class, 1);
});

it('requires the cart mutation flag and owner automation authorisation', function () {
    $workspace = retailerOrderRunWorkspace();
    $connection = connectedWoolworthsForOrderRun($workspace);
    config()->set('automation.cart_mutation_enabled', false);
    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    expect(fn () => app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
        safetyAcknowledged: true,
        productPlanReviewed: true,
    ))->toThrow(ValidationException::class);

    config()->set('automation.cart_mutation_enabled', true);
    $otherMember = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $otherMember);

    expect(fn () => app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $otherMember,
        (string) Str::uuid(),
        safetyAcknowledged: true,
        productPlanReviewed: true,
    ))->toThrow(AuthorizationException::class);
});

it('requires safety acknowledgement and a reviewed ready product plan', function () {
    $workspace = retailerOrderRunWorkspace();
    $connection = connectedWoolworthsForOrderRun($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    expect(fn () => app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class, 'Review the household safety context');

    expect(fn () => app(StartRetailerOrderRun::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $connection,
        $workspace['user'],
        (string) Str::uuid(),
        safetyAcknowledged: true,
    ))->toThrow(ValidationException::class, 'Review the exact Woolworths product plan');
});

it('starts a retailer order run from ContinueApprovedShopping instead of an AutomationRun', function () {
    $workspace = retailerOrderRunWorkspace();
    $connection = connectedWoolworthsForOrderRun($workspace);
    config()->set('automation.cart_mutation_enabled', true);
    Bus::fake([AdvanceRetailerOrderRunJob::class]);

    $fingerprint = app(MealPlanShoppingApprovalContext::class)->fingerprint($workspace['plan']);
    $workspace['plan']->update([
        'shopping_approved_at' => now(),
        'shopping_approved_by_user_id' => $workspace['user']->id,
        'shopping_approval_fingerprint' => $fingerprint,
    ]);

    $productPlan = app(ContinueApprovedShopping::class)->handle($workspace['plan']->refresh(), $workspace['user']);

    expect($productPlan)->not->toBeNull()
        ->and($productPlan->refresh()->status)->toBe(CartProductPlanStatus::Frozen)
        ->and(AutomationRun::query()->count())->toBe(0)
        ->and(RetailerOrderRun::query()->count())->toBe(1)
        ->and(RetailerOrderRun::query()->sole()->status)->toBe(RetailerOrderRunStatus::PreparingCart)
        ->and(RetailerOrderRun::query()->sole()->cart_product_plan_id)->toBe($productPlan->id);

    Bus::assertDispatched(AdvanceRetailerOrderRunJob::class);
});
