<?php

use App\Actions\Baskets\BuildBasketRunView;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\ResolveEffectiveRetailerPurchasePolicy;
use App\Actions\Retailers\SelectRetailerProducts;
use App\Actions\Retailers\UpdateMealPlanPurchasePreference;
use App\Actions\Retailers\UpdateRetailerPurchasePolicy;
use App\Actions\Retailers\ValidateRetailerProductCandidate;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerProductRankingResult;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerBulkPreference;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerHomeBrandPreference;
use App\Enums\RetailerOrganicPreference;
use App\Enums\RetailerProvider;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\MealPlan;
use App\Models\RetailerProductCandidate;
use App\Models\RetailerProductPreference;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/** @return array{user: User, team: Team, plan: MealPlan} */
function purchasePolicyWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Purchasing policy family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());

    return compact('user', 'team', 'plan');
}

/**
 * @param  array{user: User, team: Team, plan: MealPlan}  $workspace
 * @return array{grocery_plan: GroceryPlan, requirement: GroceryRequirement, run: BasketRun}
 */
function policySelectionRun(array $workspace, ?float $quantity = 500): array
{
    $effectivePolicy = app(ResolveEffectiveRetailerPurchasePolicy::class)->handle($workspace['plan']);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', 'policy-plan-'.Str::uuid()),
        'recipe_fingerprint' => hash('sha256', 'policy-recipes-'.Str::uuid()),
        'purchase_policy_snapshot' => $effectivePolicy['snapshot'],
        'purchase_policy_fingerprint' => $effectivePolicy['fingerprint'],
        'effective_basket_target_cents' => $effectivePolicy['basket_target_cents'],
        'built_at' => now(),
    ]);
    $requirement = $groceryPlan->requirements()->create([
        'team_id' => $workspace['team']->id,
        'status' => GroceryRequirementStatus::Pending,
        'display_name' => 'Pasta',
        'normalized_name' => 'pasta',
        'quantity' => $quantity,
        'unit' => 'g',
        'quantity_unknown' => $quantity === null,
        'fingerprint' => hash('sha256', 'policy-requirement-'.Str::uuid()),
        'search_queries' => ['pasta'],
        'applicable_constraints' => [],
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $workspace['team']->id,
        'meal_plan_id' => $workspace['plan']->id,
        'grocery_plan_id' => $groceryPlan->id,
        'requested_by_user_id' => $workspace['user']->id,
        'status' => BasketRunStatus::SelectingProducts,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'policy-run-'.Str::uuid()),
    ]);

    return ['grocery_plan' => $groceryPlan, 'requirement' => $requirement, 'run' => $run];
}

function policyCandidate(
    GroceryRequirement $requirement,
    string $sku,
    string $brand,
    int $priceCents,
    float $packQuantity = 500,
    ?bool $homeBrand = false,
    ?bool $organic = false,
): RetailerProductCandidate {
    return $requirement->candidates()->create([
        'team_id' => $requirement->team_id,
        'provider' => RetailerProvider::Coles,
        'sku' => $sku,
        'title' => $brand.' Pasta '.$packQuantity.'g',
        'brand' => $brand,
        'semantic_key' => 'penne',
        'origin_host' => 'www.coles.com.au',
        'product_path' => '/product/'.$sku,
        'pack_quantity' => $packQuantity,
        'pack_unit' => 'g',
        'price_cents' => $priceCents,
        'available' => true,
        'is_home_brand' => $homeBrand,
        'is_organic' => $organic,
        'attribute_evidence' => [
            'home_brand' => ['source' => 'coles_product_label'],
            'organic' => ['source' => 'coles_product_label'],
        ],
        'status' => RetailerCandidateStatus::Eligible,
        'rejection_codes' => [],
        'label_evidence' => [],
        'fingerprint' => hash('sha256', 'policy-candidate-'.$sku.Str::uuid()),
        'captured_at' => now(),
    ]);
}

it('resolves stable defaults and gives a plan target precedence over the household target', function () {
    $workspace = purchasePolicyWorkspace();
    $resolver = app(ResolveEffectiveRetailerPurchasePolicy::class);

    $defaults = $resolver->handle($workspace['plan']);

    expect($defaults['snapshot'])->toBe([
        'provider' => 'coles',
        'home_brand_preference' => 'allow',
        'bulk_preference' => 'avoid',
        'organic_preference' => 'no_preference',
        'preferred_brands' => [],
        'household_basket_target_cents' => null,
        'plan_basket_target_cents' => null,
        'effective_basket_target_source' => null,
    ])->and($defaults['basket_target_cents'])->toBeNull()
        ->and($defaults['fingerprint'])->toHaveLength(64);

    app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $workspace['user'],
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Allow,
        RetailerOrganicPreference::Prefer,
        ['Barilla', 'San Remo'],
        12_000,
    );
    app(UpdateMealPlanPurchasePreference::class)->handle(
        $workspace['plan'],
        $workspace['user'],
        9_000,
    );

    $effective = $resolver->handle($workspace['plan']->refresh());

    expect($effective['basket_target_cents'])->toBe(9_000)
        ->and($effective['snapshot']['effective_basket_target_source'])->toBe('plan')
        ->and($effective['snapshot']['preferred_brands'])->toBe(['Barilla', 'San Remo'])
        ->and($effective['fingerprint'])->not->toBe($defaults['fingerprint']);
});

it('keeps household-wide policy owner or admin controlled and team scoped', function () {
    $workspace = purchasePolicyWorkspace();
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);

    expect(fn () => app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $member,
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Avoid,
        RetailerOrganicPreference::NoPreference,
        [],
        null,
    ))->toThrow(AuthorizationException::class);

    app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $workspace['user'],
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Avoid,
        RetailerOrganicPreference::NoPreference,
        [],
        null,
    );

    expect($workspace['team']->retailerPurchasePolicies()->sole()->home_brand_preference)
        ->toBe(RetailerHomeBrandPreference::Prefer);
});

it('applies household preferences only within the fixed fifteen-percent ceiling', function (int $homeBrandPrice, string $expectedSku, bool $expectsException) {
    $workspace = purchasePolicyWorkspace();
    app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $workspace['user'],
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Allow,
        RetailerOrganicPreference::NoPreference,
        [],
        null,
    );
    $selectionRun = policySelectionRun($workspace);
    policyCandidate($selectionRun['requirement'], 'brand', 'Barilla', 100);
    policyCandidate($selectionRun['requirement'], 'home', 'Coles', $homeBrandPrice, homeBrand: true);

    app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']);
    $selection = $selectionRun['requirement']->selection()->with('candidate')->sole();

    expect($selection->candidate->sku)->toBe($expectedSku)
        ->and($selection->policy_decisions)->toContain('home_brand_preference:prefer')
        ->and($selection->material_exceptions !== [])->toBe($expectsException);
})->with([
    'preferred option fourteen percent above cheapest' => [114, 'home', false],
    'preferred option sixteen percent above cheapest' => [116, 'brand', true],
]);

it('applies explicit brand, home-brand, and organic policy before choosing an unknown-quantity pack', function () {
    $workspace = purchasePolicyWorkspace();
    app(UpdateRetailerPurchasePolicy::class)->handle(
        $workspace['team'],
        $workspace['user'],
        RetailerProvider::Coles,
        RetailerHomeBrandPreference::Prefer,
        RetailerBulkPreference::Avoid,
        RetailerOrganicPreference::Prefer,
        ['Coles'],
        null,
    );
    $selectionRun = policySelectionRun($workspace, null);
    policyCandidate($selectionRun['requirement'], 'generic-small', 'Generic', 100, 250);
    policyCandidate(
        $selectionRun['requirement'],
        'policy-match',
        'Coles',
        114,
        300,
        homeBrand: true,
        organic: true,
    );

    app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']);
    $selection = $selectionRun['requirement']->selection()->with('candidate')->sole();

    expect($selection->candidate->sku)->toBe('policy-match')
        ->and($selection->policy_decisions)->toContain(
            'preferred_brand:Coles',
            'home_brand_preference:prefer',
            'organic_preference:prefer',
            'quantity_unknown:smallest_valid_pack',
        );
});

it('prefers a non-bulk pack within the ceiling and otherwise preserves the price boundary', function (int $standardPrice, string $expectedSku) {
    $workspace = purchasePolicyWorkspace();
    $selectionRun = policySelectionRun($workspace);
    policyCandidate($selectionRun['requirement'], 'bulk', 'Bulk Co', 100, 2000);
    policyCandidate($selectionRun['requirement'], 'standard', 'Standard Co', $standardPrice, 500);

    app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']);

    expect($selectionRun['requirement']->selection()->with('candidate')->sole()->candidate->sku)
        ->toBe($expectedSku);
})->with([
    'non bulk option at fifteen percent' => [115, 'standard'],
    'non bulk option above fifteen percent' => [116, 'bulk'],
]);

it('rejects semantic tiers with missing duplicate or unknown candidate identifiers', function (array $tiers) {
    $workspace = purchasePolicyWorkspace();
    $selectionRun = policySelectionRun($workspace);
    $first = policyCandidate($selectionRun['requirement'], 'spirals', 'Spirals', 100);
    $second = policyCandidate($selectionRun['requirement'], 'spaghetti', 'Spaghetti', 90);
    $first->update(['semantic_key' => 'spirals']);
    $second->update(['semantic_key' => 'spaghetti']);
    $resolvedTiers = [];
    foreach ($tiers as $tier) {
        $candidateIds = [];
        foreach ($tier['candidate_ids'] as $id) {
            $candidateIds[] = match ($id) {
                'first' => $first->id,
                'second' => $second->id,
                default => (int) $id,
            };
        }
        $resolvedTiers[] = ['candidate_ids' => $candidateIds];
    }
    $this->app->bind(RetailerProductRanker::class, fn () => new class($resolvedTiers) implements RetailerProductRanker
    {
        /** @param list<array{candidate_ids: list<int>}> $tiers */
        public function __construct(private readonly array $tiers) {}

        public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
        {
            return new RetailerProductRankingResult([[
                'requirement_id' => $request->requirements[0]['requirement_id'],
                'tiers' => $this->tiers,
                'confidence' => 0.4,
            ]]);
        }
    });

    expect(fn () => app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']))
        ->toThrow(ValidationException::class)
        ->and($selectionRun['requirement']->selection()->count())->toBe(0);
})->with([
    'missing' => [[['candidate_ids' => ['first']]]],
    'duplicate' => [[['candidate_ids' => ['first', 'first', 'second']]]],
    'unknown' => [[['candidate_ids' => ['first', 'second', 999999]]]],
]);

it('normalizes home-brand and organic attributes only when retailer evidence is present', function () {
    $workspace = purchasePolicyWorkspace();
    $selectionRun = policySelectionRun($workspace);
    $candidate = [
        'origin_host' => 'www.coles.com.au',
        'sku' => 'evidenced-pasta',
        'title' => 'Coles Organic Pasta 500g',
        'brand' => 'Coles',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => 150,
        'available' => true,
        'is_home_brand' => true,
        'is_organic' => true,
    ];

    $withoutEvidence = app(ValidateRetailerProductCandidate::class)->handle(
        $selectionRun['requirement'],
        $candidate,
    );
    $withEvidence = app(ValidateRetailerProductCandidate::class)->handle(
        $selectionRun['requirement'],
        [
            ...$candidate,
            'attribute_evidence' => [
                'home_brand' => ['source' => 'coles_product_label'],
                'organic' => ['source' => 'coles_product_label'],
            ],
        ],
    );

    expect($withoutEvidence['is_home_brand'])->toBeNull()
        ->and($withoutEvidence['is_organic'])->toBeNull()
        ->and($withEvidence['is_home_brand'])->toBeTrue()
        ->and($withEvidence['is_organic'])->toBeTrue();
});

it('exposes the household policy through additive web and v1 contracts with owner controls', function () {
    $workspace = purchasePolicyWorkspace();
    Sanctum::actingAs($workspace['user']);

    $this->getJson('/api/v1/retailer-purchase-policy')
        ->assertOk()
        ->assertJsonPath('policy.home_brand_preference', 'allow')
        ->assertJsonPath('policy.bulk_preference', 'avoid')
        ->assertJsonPath('policy.default_basket_target_cents', null)
        ->assertJsonPath('can.update', true);

    $this->putJson('/api/v1/retailer-purchase-policy', [
        'home_brand_preference' => 'avoid',
        'bulk_preference' => 'allow',
        'organic_preference' => 'prefer',
        'preferred_brands' => ['Barilla'],
        'default_basket_target_cents' => 10_000,
    ])->assertOk()
        ->assertJsonPath('policy.home_brand_preference', 'avoid')
        ->assertJsonPath('policy.preferred_brands.0', 'Barilla');

    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();
    Sanctum::actingAs($member);
    $this->putJson('/api/v1/retailer-purchase-policy', [
        'home_brand_preference' => 'prefer',
        'bulk_preference' => 'avoid',
        'organic_preference' => 'no_preference',
        'preferred_brands' => [],
        'default_basket_target_cents' => null,
    ])->assertForbidden();
});

it('accepts an empty preferred-brand field from the grocery settings form', function () {
    $workspace = purchasePolicyWorkspace();

    $response = $this->actingAs($workspace['user'])
        ->put('/settings/groceries', [
            'home_brand_preference' => 'allow',
            'bulk_preference' => 'avoid',
            'organic_preference' => 'no_preference',
            'preferred_brands' => '',
            'default_basket_target_cents' => '',
        ]);
    $response->assertRedirect('/settings/groceries');

    expect($workspace['team']->retailerPurchasePolicies()->sole()->preferred_brands)
        ->toBe([]);
});

it('lets a plan editor set the plan target while rejecting cross-team access', function () {
    $workspace = purchasePolicyWorkspace();

    $this->actingAs($workspace['user'])
        ->putJson('/meal-plans/'.$workspace['plan']->id.'/purchase-preference', [
            'basket_target_cents' => 8_500,
        ])->assertOk()
        ->assertJsonPath('purchase_preference.basket_target_cents', 8_500);

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other policy family');
    $this->actingAs($outsider)
        ->putJson('/meal-plans/'.$workspace['plan']->id.'/purchase-preference', [
            'basket_target_cents' => 1,
        ])->assertNotFound();
});

it('saves an eligible discovered alternative for next time without changing the current basket and supports revocation', function () {
    $workspace = purchasePolicyWorkspace();
    $selectionRun = policySelectionRun($workspace);
    policyCandidate($selectionRun['requirement'], 'current', 'Current', 100);
    $alternative = policyCandidate($selectionRun['requirement'], 'next-time', 'Next Time', 110);
    app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']);
    $item = $selectionRun['run']->items()->sole();
    $currentSku = $item->sku;

    $response = $this->actingAs($workspace['user'])
        ->putJson('/basket-runs/'.$selectionRun['run']->id.'/items/'.$item->id.'/product-preference', [
            'retailer_product_candidate_id' => $alternative->id,
        ])->assertOk()
        ->assertJsonPath('preference.sku', 'next-time');

    $preference = RetailerProductPreference::query()
        ->where('id', $response->json('preference.id'))
        ->firstOrFail();
    $basketView = app(BuildBasketRunView::class)->handle(
        $selectionRun['run']->refresh(),
        $workspace['user'],
    );
    expect($selectionRun['run']->items()->sole()->sku)->toBe($currentSku)
        ->and($preference->created_by_user_id)->toBe($workspace['user']->id)
        ->and($preference->revoked_at)->toBeNull()
        ->and($basketView['items'][0]['alternatives'][0]['candidate_id'])->toBe($alternative->id)
        ->and($basketView['items'][0]['alternatives'][0]['captured_price_cents'])->toBe(110)
        ->and($basketView['items'][0]['can_prefer'])->toBeTrue();

    $this->deleteJson('/retailer-product-preferences/'.$preference->id)
        ->assertOk()
        ->assertJsonPath('preference.revoked', true);

    expect($preference->refresh()->revoked_at)->not->toBeNull();
});

it('refuses to learn a product preference from a rejected or unrelated candidate', function (string $kind) {
    $workspace = purchasePolicyWorkspace();
    $selectionRun = policySelectionRun($workspace);
    policyCandidate($selectionRun['requirement'], 'current', 'Current', 100);
    app(SelectRetailerProducts::class)->handle($selectionRun['grocery_plan']);
    $item = $selectionRun['run']->items()->sole();

    if ($kind === 'rejected') {
        $candidate = policyCandidate($selectionRun['requirement'], 'rejected', 'Rejected', 110);
        $candidate->update(['status' => RetailerCandidateStatus::Rejected]);
    } else {
        $otherRequirement = $selectionRun['grocery_plan']->requirements()->create([
            'team_id' => $workspace['team']->id,
            'status' => GroceryRequirementStatus::Pending,
            'display_name' => 'Rice',
            'normalized_name' => 'rice',
            'quantity' => 500,
            'unit' => 'g',
            'quantity_unknown' => false,
            'fingerprint' => hash('sha256', 'unrelated-'.Str::uuid()),
            'search_queries' => ['rice'],
            'applicable_constraints' => [],
        ]);
        $candidate = policyCandidate($otherRequirement, 'unrelated', 'Unrelated', 110);
    }

    $this->actingAs($workspace['user'])
        ->putJson('/basket-runs/'.$selectionRun['run']->id.'/items/'.$item->id.'/product-preference', [
            'retailer_product_candidate_id' => $candidate->id,
        ])->assertUnprocessable();

    expect(RetailerProductPreference::query()->count())->toBe(0);
})->with(['rejected', 'unrelated']);
