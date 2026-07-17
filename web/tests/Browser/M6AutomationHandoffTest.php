<?php

use App\Actions\Automation\ClaimBrowserConnection;
use App\Actions\Automation\CreateBrowserPairing;
use App\Actions\Automation\StartCartPreparation;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationReconciliationStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use App\Enums\ShoppingListStatus;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function browserM6Workspace(bool $paired = true): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Browser handoff family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today()->addDays(6));
    $plan->update(['planning_confirmed_at' => now()]);
    $plan->refresh();
    $list = ShoppingList::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'created_by_user_id' => $user->id,
        'revision' => 1,
        'source_plan_revision' => $plan->revision,
        'status' => ShoppingListStatus::Draft,
    ]);
    $item = $list->items()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'source_kind' => ShoppingListItemSourceKind::Manual,
        'category' => ShoppingListItemCategory::DairyAndEggs,
        'name' => 'Milk',
        'normalized_name' => 'milk',
        'quantity' => 1,
        'unit' => 'bottle',
        'included' => true,
        'position' => 1,
    ]);
    $connection = null;

    if ($paired) {
        $pairing = app(CreateBrowserPairing::class)->handle($team, $user);
        $connection = app(ClaimBrowserConnection::class)->handle($pairing['pairing_code'], 'Kitchen Chrome')['connection'];
    }

    return compact('user', 'team', 'plan', 'list', 'item', 'connection');
}

it('pairs and controls a frozen retailer handoff in the real shopping workspace', function () {
    $workspace = browserM6Workspace();
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Prepare retailer cart')
        ->assertSee('Kitchen Chrome')
        ->assertSee('Chef uses only a retailer tab you select')
        ->pressAndWaitFor('Prepare cart')
        ->assertSee('Waiting for your retailer tab')
        ->assertSee('frozen list revision 1')
        ->assertSee('checkout, address changes, authentication, delivery choices, and payment stay with you')
        ->pressAndWaitFor('Pause')
        ->assertSee('Paused')
        ->pressAndWaitFor('Resume Chef')
        ->assertSee('Waiting for your retailer tab')
        ->assertNoJavaScriptErrors();
});

it('shows explicit approval and reconciliation controls at a narrow viewport', function () {
    $workspace = browserM6Workspace();
    $run = app(StartCartPreparation::class)->handle(
        $workspace['list'],
        $workspace['user'],
        Retailer::query()->where('slug', 'coles')->sole(),
        $workspace['connection'],
        1,
    );
    $run->update([
        'status' => AutomationRunStatus::AwaitingApproval,
        'progress' => ['total' => 1, 'added' => 1, 'unresolved' => 0],
    ]);
    $run->approvals()->create([
        'team_id' => $workspace['team']->id,
        'risk_kind' => 'material_substitution',
        'proposed_action' => 'Keep Coles Full Cream Milk 2L',
        'consequence' => 'The preferred pack was unavailable. Approving keeps this item in the reviewable cart and does not place an order.',
        'status' => AutomationApprovalStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
    $run->reconciliations()->create([
        'team_id' => $workspace['team']->id,
        'shopping_list_item_id' => $workspace['item']->id,
        'status' => AutomationReconciliationStatus::Substituted,
        'intended_name' => 'Milk',
        'product_name' => 'Coles Full Cream Milk 2L',
        'quantity' => 1,
        'unit_price' => 3.10,
        'total_price' => 3.10,
        'substitution_reason' => 'Preferred pack unavailable',
    ]);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.shopping.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Waiting for your decision')
        ->assertSee('Keep Coles Full Cream Milk 2L')
        ->assertSee('Approving keeps this item in the reviewable cart')
        ->assertSee('Prepared cart')
        ->assertSee('$3.10')
        ->assertSee('Approve this action')
        ->assertSee('Reject and take over')
        ->assertNoJavaScriptErrors();
});
