<?php

use App\Actions\Baskets\BuildBasketRunView;
use App\Actions\Baskets\ProjectBasketRunPublicState;
use App\Actions\Baskets\RecordStagehandFallbackCount;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerWorkerResultStatus;
use App\Models\BasketRun;
use App\Models\BasketRunStatusTransition;
use App\Models\GroceryPlan;
use App\Models\MealPlanAdjustmentDraft;
use App\Models\RetailerConnection;
use App\Models\User;
use App\Notifications\BasketRunStatusNotification;
use App\Observers\BasketRunObserver;
use App\Retailer\Data\RetailerWorkerResult;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** @return array{user: User, run: BasketRun, grocery_plan: GroceryPlan} */
function publicBasketRun(BasketRunStatus $status): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Public basket family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today(), 'Public state plan');
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'public-state-context',
        'context_lookup_hash' => hash('sha256', 'public-state-context-'.Str::uuid()),
    ]);
    $effective = app(ResolveEffectiveRetailerPurchasePolicy::class)->handle($plan);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', 'public-plan-'.Str::uuid()),
        'recipe_fingerprint' => hash('sha256', 'public-recipes-'.Str::uuid()),
        'purchase_policy_snapshot' => $effective['snapshot'],
        'purchase_policy_fingerprint' => $effective['fingerprint'],
        'effective_basket_target_cents' => 10_000,
        'built_at' => now(),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection->id,
        'requested_by_user_id' => $user->id,
        'status' => $status,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'public-run-'.Str::uuid()),
    ]);

    return ['user' => $user, 'run' => $run, 'grocery_plan' => $groceryPlan];
}

it('projects internal orchestration into a stable public basket state', function (BasketRunStatus $status, string $state, ?string $outcome) {
    $workspace = publicBasketRun($status);
    $projection = app(ProjectBasketRunPublicState::class)->handle($workspace['run']);

    expect($projection['state'])->toBe($state)
        ->and($projection['outcome'])->toBe($outcome);
})->with([
    'routine work' => [BasketRunStatus::DiscoveringProducts, 'preparing', null],
    'resolution preparation' => [BasketRunStatus::PreparingResolution, 'preparing', null],
    'first connection' => [BasketRunStatus::WaitingForConnection, 'connection_required', null],
    'reauthentication' => [BasketRunStatus::ReauthenticationRequired, 'connection_required', null],
    'coherent plan review' => [BasketRunStatus::NeedsPlanReview, 'plan_review_required', null],
    'verified basket' => [BasketRunStatus::Ready, 'ready', 'basket_ready'],
    'read only rollout' => [BasketRunStatus::ProductsSelected, 'ready', 'products_selected'],
    'restored basket' => [BasketRunStatus::Restored, 'ready', 'basket_restored'],
    'missing product' => [BasketRunStatus::NeedsProduct, 'needs_attention', null],
    'uncertain basket' => [BasketRunStatus::Uncertain, 'needs_attention', null],
    'failure' => [BasketRunStatus::Failed, 'failed', null],
    'cancelled' => [BasketRunStatus::Cancelled, 'needs_attention', 'cancelled'],
]);

it('adds public state, effective policy, attention and adjustment summaries to the basket contract', function () {
    $workspace = publicBasketRun(BasketRunStatus::NeedsPlanReview);
    $workspace['run']->update([
        'attention_kind' => MealPlanAdjustmentKind::BudgetOverrun->value,
        'attention_details' => [
            'budget_target_cents' => 10_000,
            'selected_subtotal_cents' => 11_500,
        ],
        'chef_subtotal_cents' => 11_500,
    ]);
    MealPlanAdjustmentDraft::query()->create([
        'team_id' => $workspace['run']->team_id,
        'meal_plan_id' => $workspace['run']->meal_plan_id,
        'basket_run_id' => $workspace['run']->id,
        'originating_plan_revision' => 1,
        'kind' => MealPlanAdjustmentKind::BudgetOverrun,
        'status' => MealPlanAdjustmentDraftStatus::Pending,
        'input_fingerprint' => hash('sha256', 'public-adjustment'),
        'generated_at' => now(),
    ]);

    $view = app(BuildBasketRunView::class)->handle($workspace['run']->refresh(), $workspace['user']);

    expect($view['public_state'])->toBe('plan_review_required')
        ->and($view['public_outcome'])->toBeNull()
        ->and($view['attention']['kind'])->toBe('budget_overrun')
        ->and($view['attention']['budget_target_cents'])->toBe(10_000)
        ->and($view['effective_policy']['bulk_preference'])->toBe('avoid')
        ->and($view['effective_policy']['basket_target_cents'])->toBe(10_000)
        ->and($view['adjustment']['kind'])->toBe('budget_overrun')
        ->and($view['adjustment']['status'])->toBe('pending')
        ->and($view['can']['override_budget'])->toBeTrue();
});

it('notifies only ready or actionable states and includes the verified ready total', function () {
    Notification::fake();
    $workspace = publicBasketRun(BasketRunStatus::SelectingProducts);
    $observer = app(BasketRunObserver::class);

    $workspace['run']->update(['status' => BasketRunStatus::PreparingResolution]);
    $observer->updated($workspace['run']);
    Notification::assertNothingSent();

    $workspace['run']->update([
        'status' => BasketRunStatus::NeedsPlanReview,
        'attention_kind' => MealPlanAdjustmentKind::ProductUnavailable->value,
    ]);
    $observer->updated($workspace['run']);
    Notification::assertSentTo($workspace['user'], BasketRunStatusNotification::class);

    $workspace['run']->update([
        'status' => BasketRunStatus::Ready,
        'chef_subtotal_cents' => 1_234,
    ]);
    $payload = (new BasketRunStatusNotification($workspace['run']))->toDatabase($workspace['user']);
    expect($payload['product_count'])->toBe(0)
        ->and($payload['total_cents'])->toBe(1_234)
        ->and($payload['message'])->toContain('$12.34');
});

it('records safe status transitions without page or browser evidence', function () {
    $workspace = publicBasketRun(BasketRunStatus::BuildingRequirements);
    $workspace['run']->update([
        'status' => BasketRunStatus::SelectingProducts,
        'failure_code' => 'safe_internal_code',
        'failure_message' => 'A message that must not be copied into instrumentation.',
    ]);
    app(BasketRunObserver::class)->updated($workspace['run']);

    $transition = BasketRunStatusTransition::query()->latest('id')->firstOrFail();
    expect($transition->from_status)->toBe('building_requirements')
        ->and($transition->to_status)->toBe('selecting_products')
        ->and($transition->reason_code)->toBe('safe_internal_code')
        ->and($transition->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($transition->getAttributes())->not->toHaveKey('failure_message');
});

it('records only the bounded Stagehand fallback count from worker results', function () {
    $workspace = publicBasketRun(BasketRunStatus::ReplacingBasket);
    $result = new RetailerWorkerResult(
        RetailerWorkerResultStatus::Succeeded,
        null,
        null,
        ['stagehand_fallback_count' => 3],
    );

    app(RecordStagehandFallbackCount::class)->handle($workspace['run'], $result);

    expect($workspace['run']->refresh()->stagehand_fallback_count)->toBe(3);
});
