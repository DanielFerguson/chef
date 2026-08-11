<?php

use App\Actions\Baskets\RecordBasketSnapshot;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerSelectionMethod;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\MealPlan;
use App\Models\MealPlanAdjustmentDraft;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @return array{user: User, plan: MealPlan, run: BasketRun}
 */
function basketPreparationBrowserWorkspace(
    BasketRunStatus $status,
    bool $withProduct = false,
): array {
    Queue::fake();
    config()->set('retailer.features.experience', true);
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Basket browser family');
    $plan = app(StartMealPlan::class)->handle(
        $team,
        $user,
        today(),
        today()->addDays(6),
        'Weeknight dinners',
    );
    $connection = $status === BasketRunStatus::WaitingForConnection
        ? null
        : RetailerConnection::query()->create([
            'team_id' => $team->id,
            'owner_user_id' => $user->id,
            'provider' => RetailerProvider::Coles,
            'status' => RetailerConnectionStatus::Connected,
            'browserbase_context_id' => 'browser_context',
            'context_lookup_hash' => hash('sha256', "browser-context-{$plan->id}"),
            'authenticated_at' => now(),
            'last_verified_at' => now(),
        ]);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', "browser-grocery-{$plan->id}"),
        'recipe_fingerprint' => hash('sha256', "browser-recipes-{$plan->id}"),
        'built_at' => now(),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $plan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection?->id,
        'requested_by_user_id' => $user->id,
        'status' => $status,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', "browser-run-{$plan->id}"),
        'failure_code' => $status === BasketRunStatus::NeedsProduct ? 'no_valid_candidate' : null,
        'failure_message' => match ($status) {
            BasketRunStatus::NeedsProduct => 'No pasta option passed every required check.',
            BasketRunStatus::Uncertain => 'The Coles basket changed while Chef was verifying it.',
            BasketRunStatus::Failed => 'Coles stopped responding before any basket change.',
            default => null,
        },
        'replaced_line_count' => $withProduct ? 1 : 0,
        'chef_subtotal_cents' => $withProduct ? 300 : null,
        'retailer_total_cents' => $withProduct ? 300 : null,
        'basket_captured_at' => $withProduct ? now() : null,
    ]);

    if ($withProduct) {
        $requirement = $groceryPlan->requirements()->create([
            'team_id' => $team->id,
            'status' => GroceryRequirementStatus::Selected,
            'display_name' => 'Penne pasta',
            'normalized_name' => 'pasta',
            'normalized_form' => 'penne',
            'quantity' => 800,
            'unit' => 'g',
            'quantity_unknown' => false,
            'fingerprint' => hash('sha256', "browser-requirement-{$plan->id}"),
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
            'fingerprint' => hash('sha256', "browser-candidate-{$plan->id}"),
            'captured_at' => now(),
        ]);
        $requirement->candidates()->create([
            'team_id' => $team->id,
            'provider' => RetailerProvider::Coles,
            'sku' => '4459012',
            'title' => 'Barilla Penne Rigate 500g',
            'brand' => 'Barilla',
            'semantic_key' => 'penne pasta',
            'origin_host' => 'www.coles.com.au',
            'product_path' => '/product/barilla-penne-rigate-4459012',
            'pack_quantity' => 500,
            'pack_unit' => 'g',
            'price_cents' => 210,
            'available' => true,
            'status' => RetailerCandidateStatus::Eligible,
            'rejection_codes' => [],
            'label_evidence' => [],
            'fingerprint' => hash('sha256', "browser-alternative-{$plan->id}"),
            'captured_at' => now(),
        ]);
        $selection = $requirement->selection()->create([
            'team_id' => $team->id,
            'retailer_product_candidate_id' => $candidate->id,
            'method' => RetailerSelectionMethod::Deterministic,
            'confidence' => 1,
            'low_confidence' => false,
            'reasoning' => 'Two 500 g packs cover 800 g under the balanced price and waste policy.',
            'pack_count' => 2,
            'required_quantity' => 800,
            'total_quantity' => 1000,
            'waste_quantity' => 200,
            'total_price_cents' => 300,
            'selection_checksum' => hash('sha256', "browser-selection-{$plan->id}"),
            'revalidation_checksum' => $candidate->fingerprint,
            'selected_at' => now(),
            'revalidated_at' => now(),
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
            'verification_checksum' => hash('sha256', "browser-item-{$plan->id}"),
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
    }

    return compact('user', 'plan', 'run');
}

/** @param array{user: User, plan: MealPlan, run: BasketRun} $workspace */
function addBrowserPlanAdjustment(
    array $workspace,
    MealPlanAdjustmentKind $kind,
): MealPlanAdjustmentDraft {
    $run = $workspace['run'];
    $run->groceryPlan()->update([
        'effective_basket_target_cents' => $kind === MealPlanAdjustmentKind::BudgetOverrun
            ? 1_000
            : null,
    ]);
    $run->update([
        'status' => BasketRunStatus::NeedsPlanReview,
        'attention_kind' => $kind->value,
        'attention_details' => [
            'budget_target_cents' => $kind === MealPlanAdjustmentKind::BudgetOverrun ? 1_000 : null,
            'selected_subtotal_cents' => $kind === MealPlanAdjustmentKind::BudgetOverrun ? 1_250 : null,
            'blocked_requirement_count' => $kind === MealPlanAdjustmentKind::ProductUnavailable ? 1 : null,
        ],
        'chef_subtotal_cents' => $kind === MealPlanAdjustmentKind::BudgetOverrun ? 1_250 : null,
    ]);
    $draft = MealPlanAdjustmentDraft::query()->create([
        'team_id' => $run->team_id,
        'meal_plan_id' => $run->meal_plan_id,
        'basket_run_id' => $run->id,
        'originating_plan_revision' => (int) ($workspace['plan']->revision ?? 0),
        'kind' => $kind,
        'status' => MealPlanAdjustmentDraftStatus::Pending,
        'input_fingerprint' => hash('sha256', "browser-adjustment-{$run->id}-{$kind->value}"),
        'generated_at' => now(),
    ]);
    $draft->items()->create([
        'team_id' => $run->team_id,
        'replacement_title' => 'Lentil bolognese',
        'replacement_summary' => 'A less expensive pantry-led dinner that avoids the unavailable ingredient.',
        'estimated_minutes' => 30,
        'estimated_cost_cents' => 800,
        'covered_requirement_ids' => [],
    ]);

    return $draft;
}

it('presents the just-in-time Coles connection handoff at desktop and 390 by 844', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::WaitingForConnection);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Connect Coles to continue')
        ->assertSee('Recipes are preparing in the background.')
        ->assertPresent('[data-testid="connect-coles"]')
        ->resize(390, 844)
        ->assertSee('Connect Coles')
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('shows automatic background progress without blocking the planning workspace', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::DiscoveringProducts);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))->on()->desktop()
        ->assertSee('Preparing your Coles basket')
        ->assertSee('You can leave this page.')
        ->assertSee('View basket')
        ->resize(390, 844)
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('keeps reauthentication to one continue-with-Coles decision', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::ReauthenticationRequired);
    $this->actingAs($workspace['user']);

    visit(route('meal-plans.show', $workspace['plan']))
        ->resize(390, 844)
        ->assertSee('Continue with Coles')
        ->assertDontSee('Allow Chef & continue')
        ->assertNoJavaScriptErrors();
});

it('finishes read-only discovery without claiming that Coles changed', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::ProductsSelected, true);
    $workspace['run']->update([
        'failure_message' => 'Chef selected valid Coles products, but basket changes are paused for this rollout. The existing basket was not changed.',
    ]);
    $this->actingAs($workspace['user']);

    visit(route('basket-runs.show', $workspace['run']))
        ->resize(390, 844)
        ->assertSee('Products selected; basket unchanged')
        ->assertSee('The existing basket was not changed.')
        ->assertDontSee('confirmed from the actual Coles basket')
        ->assertNoJavaScriptErrors();
});

it('shows a verified basket, pack reasoning, restoration, and owner review on desktop and mobile', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::Ready, true);
    $this->actingAs($workspace['user']);

    visit(route('basket-runs.show', $workspace['run']))->on()->desktop()
        ->assertSee('Basket ready')
        ->assertSee('confirmed from the actual Coles basket')
        ->assertSee('Coles Penne Pasta 500g')
        ->assertSee('$3.00')
        ->click('Why this pack')
        ->assertSee('Two 500 g packs cover 800 g')
        ->assertSee('Barilla Penne Rigate 500g')
        ->click('Prefer next time')
        ->assertSee('Saved for next time')
        ->assertSee('Restore previous basket')
        ->assertSee('Review in Coles')
        ->resize(390, 844)
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('lets an owner maintain the household grocery policy at desktop and mobile', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::Ready);
    $this->actingAs($workspace['user']);

    visit(route('groceries.edit'))->on()->desktop()
        ->assertSee('Grocery preferences')
        ->select('#home_brand_preference', 'prefer')
        ->select('#organic_preference', 'prefer')
        ->type('#preferred_brands', 'Barilla, Mutti')
        ->type('#default_basket_target', '90.00')
        ->press('Save')
        ->assertSee('Products to prefer next time')
        ->resize(390, 844)
        ->assertScript('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();

    expect($workspace['plan']->team->retailerPurchasePolicies()->sole()->default_basket_target_cents)
        ->toBe(9_000);
});

it('presents an over-budget basket as one owner decision before mutation', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::SelectingProducts);
    addBrowserPlanAdjustment($workspace, MealPlanAdjustmentKind::BudgetOverrun);
    $this->actingAs($workspace['user']);

    visit(route('basket-runs.show', $workspace['run']))
        ->resize(390, 844)
        ->assertSee('This basket is over your target')
        ->assertSee('$12.50')
        ->assertSee('$10.00')
        ->assertSee('Lentil bolognese')
        ->assertSee('Use this basket')
        ->assertSee('Review cheaper plan')
        ->click('Use this basket')
        ->assertSee('Products selected; basket unchanged')
        ->assertNoJavaScriptErrors();
});

it('shows one coherent unavailable-product plan diff and leaves reapproval in planning', function () {
    $workspace = basketPreparationBrowserWorkspace(BasketRunStatus::SelectingProducts);
    addBrowserPlanAdjustment($workspace, MealPlanAdjustmentKind::ProductUnavailable);
    $this->actingAs($workspace['user']);

    visit(route('basket-runs.show', $workspace['run']))
        ->resize(390, 844)
        ->assertSee('Chef found a coherent plan alternative')
        ->assertSee('Your existing Coles basket is unchanged.')
        ->assertSee('Lentil bolognese')
        ->click('Review revised plan')
        ->assertSee('Weeknight dinners')
        ->assertNoJavaScriptErrors();
});

it('keeps blocked, failed, and uncertain outcomes visibly distinct from confirmation', function (
    BasketRunStatus $status,
    string $expected,
) {
    $workspace = basketPreparationBrowserWorkspace($status);
    $this->actingAs($workspace['user']);

    visit(route('basket-runs.show', $workspace['run']))
        ->resize(390, 844)
        ->assertSee($expected)
        ->assertDontSee('confirmed from the actual Coles basket')
        ->assertNoJavaScriptErrors();
})->with([
    'no safe product' => [BasketRunStatus::NeedsProduct, 'No pasta option passed every required check.'],
    'pre-mutation failure' => [BasketRunStatus::Failed, 'Coles stopped responding before any basket change.'],
    'uncertain concurrent edit' => [BasketRunStatus::Uncertain, 'not confirmed'],
]);
