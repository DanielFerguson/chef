<?php

use App\Actions\Automation\BuildCartProductPlan;
use App\Actions\Automation\StartRetailerConnection;
use App\Actions\Automation\VerifyRetailerConnection;
use App\Actions\Households\RecordConstraint;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\CartProductCandidateSelector;
use App\Ai\Testing\DeterministicCartProductCandidateSelector;
use App\Automation\Contracts\RetailerProductDiscovery;
use App\Automation\Testing\FakeRetailerProductDiscovery;
use App\Enums\CartProductPlanItemStatus;
use App\Enums\CartProductPlanStatus;
use App\Enums\ConstraintKind;
use App\Enums\ShoppingListGenerationStatus;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListRevision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{user: User, team: Team, plan: MealPlan, list: ShoppingList, item: ShoppingListItem, revision: ShoppingListRevision, connection: RetailerConnection, discovery: FakeRetailerProductDiscovery} */
function aiCartProductWorkspace(string $itemName = 'finely grated parmesan'): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'AI match family');
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
        'quantity' => 100,
        'unit' => 'g',
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
                'estimated_price' => 5.5,
                'product_match' => null,
                'source_planned_meal_ids' => [],
            ]],
        ],
    ]);

    config()->set('automation.connection_enabled', true);
    $session = app(StartRetailerConnection::class)->handle($team, $user);
    app(VerifyRetailerConnection::class)->handle($session, $user);
    $discovery = app(RetailerProductDiscovery::class);
    expect($discovery)->toBeInstanceOf(FakeRetailerProductDiscovery::class);

    return [
        'user' => $user,
        'team' => $team,
        'plan' => $plan,
        'list' => $list,
        'item' => $item,
        'revision' => $revision,
        'connection' => $session->retailerConnection->refresh(),
        'discovery' => $discovery,
    ];
}

it('keeps heuristic confident matches without calling AI selection', function () {
    $workspace = aiCartProductWorkspace('full cream milk');
    $selector = Mockery::mock(CartProductCandidateSelector::class);
    $selector->shouldNotReceive('select');
    app()->instance(CartProductCandidateSelector::class, $selector);

    $plan = app(BuildCartProductPlan::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $workspace['connection'],
        $workspace['user'],
        force: true,
    );

    expect($plan->status)->toBe(CartProductPlanStatus::Ready)
        ->and($plan->items()->sole()->decision_reason)->toBe('confident_read_only_discovery');
});

it('auto-applies the cheapest AI catalogue pick when heuristic confidence is ambiguous', function () {
    $workspace = aiCartProductWorkspace();
    $workspace['discovery']->returnAmbiguousCandidates = true;

    $plan = app(BuildCartProductPlan::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $workspace['connection'],
        $workspace['user'],
        force: true,
    );
    $item = $plan->items()->sole();

    expect($plan->status)->toBe(CartProductPlanStatus::Ready)
        ->and($item->status)->toBe(CartProductPlanItemStatus::Exact)
        ->and($item->decision_reason)->toBe('ai_best_fit_cheapest')
        ->and($item->selected_product['price'])->toBe(3.5);
});

it('does not auto-apply AI picks when strict safety constraints require exact matches', function () {
    $workspace = aiCartProductWorkspace();
    $workspace['discovery']->returnAmbiguousCandidates = true;
    app(RecordConstraint::class)->handle(
        team: $workspace['team'],
        user: $workspace['user'],
        kind: ConstraintKind::Allergy,
        subject: 'Dairy',
        directlyConfirmed: true,
    );

    $plan = app(BuildCartProductPlan::class)->handle(
        $workspace['list']->refresh(),
        $workspace['revision'],
        $workspace['connection'],
        $workspace['user'],
        force: true,
    );
    $item = $plan->items()->sole();

    expect($plan->status)->toBe(CartProductPlanStatus::NeedsReview)
        ->and($item->status)->toBe(CartProductPlanItemStatus::Ambiguous)
        ->and($item->decision_reason)->toBe('explicit_safety_match_required')
        ->and($item->selected_product)->toBeNull();
});

it('ignores AI selections that invent unknown external ids', function () {
    $workspace = aiCartProductWorkspace();
    $workspace['discovery']->returnAmbiguousCandidates = true;
    $selector = new DeterministicCartProductCandidateSelector;
    $selector->forcedExternalIdsByItemId = [
        $workspace['item']->id => 'not-a-real-candidate',
    ];
    app()->instance(CartProductCandidateSelector::class, $selector);

    $plan = app(BuildCartProductPlan::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $workspace['connection'],
        $workspace['user'],
        force: true,
    );
    $item = $plan->items()->sole();

    expect($plan->status)->toBe(CartProductPlanStatus::NeedsReview)
        ->and($item->status)->toBe(CartProductPlanItemStatus::Ambiguous)
        ->and($item->decision_reason)->toBe('ambiguous_candidates')
        ->and($item->selected_product)->toBeNull();
});

it('leaves human decisions when the AI selector abstains', function () {
    $workspace = aiCartProductWorkspace();
    $workspace['discovery']->returnAmbiguousCandidates = true;
    $selector = new DeterministicCartProductCandidateSelector;
    $selector->abstain = true;
    app()->instance(CartProductCandidateSelector::class, $selector);

    $plan = app(BuildCartProductPlan::class)->handle(
        $workspace['list'],
        $workspace['revision'],
        $workspace['connection'],
        $workspace['user'],
        force: true,
    );
    $item = $plan->items()->sole();

    expect($plan->status)->toBe(CartProductPlanStatus::NeedsReview)
        ->and($item->status)->toBe(CartProductPlanItemStatus::Ambiguous)
        ->and($item->selected_product)->toBeNull();
});
