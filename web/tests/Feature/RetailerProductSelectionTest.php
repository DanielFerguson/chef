<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\ChooseBalancedPack;
use App\Actions\Retailers\SelectRetailerProducts;
use App\Actions\Retailers\ValidateRetailerProductCandidate;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerProductRankingResult;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerSelectionMethod;
use App\Jobs\SelectRetailerProductsJob;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\RetailerProductCandidate;
use App\Models\RetailerProductPreference;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, team: Team, grocery_plan: GroceryPlan, run: BasketRun} */
function retailerSelectionWorkspace(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Product selection family');
    $mealPlan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', 'grocery-input-'.$mealPlan->id),
        'recipe_fingerprint' => hash('sha256', 'recipe-input-'.$mealPlan->id),
        'built_at' => now(),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'requested_by_user_id' => $user->id,
        'status' => BasketRunStatus::SelectingProducts,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'basket-input-'.$mealPlan->id),
    ]);

    return compact('user', 'team', 'groceryPlan', 'run') + [
        'grocery_plan' => $groceryPlan,
    ];
}

/** @param list<array<string, mixed>> $constraints */
function retailerRequirement(
    GroceryPlan $groceryPlan,
    string $name = 'pasta',
    ?float $quantity = 600,
    ?string $unit = 'g',
    array $constraints = [],
): GroceryRequirement {
    return $groceryPlan->requirements()->create([
        'team_id' => $groceryPlan->team_id,
        'status' => GroceryRequirementStatus::Pending,
        'display_name' => Str::headline($name),
        'normalized_name' => $name,
        'normalized_form' => null,
        'quantity' => $quantity,
        'unit' => $unit,
        'quantity_unknown' => $quantity === null,
        'fingerprint' => hash('sha256', implode('|', [$name, $quantity ?? 'unknown', $unit ?? 'none', Str::uuid()])),
        'search_queries' => [$name],
        'applicable_constraints' => $constraints,
    ]);
}

function retailerCandidate(
    GroceryRequirement $requirement,
    string $sku,
    string $title,
    string $semanticKey,
    float $packQuantity,
    int $priceCents,
    string $packUnit = 'g',
): RetailerProductCandidate {
    return $requirement->candidates()->create([
        'team_id' => $requirement->team_id,
        'provider' => RetailerProvider::Coles,
        'sku' => $sku,
        'title' => $title,
        'brand' => 'Test',
        'semantic_key' => $semanticKey,
        'origin_host' => 'www.coles.com.au',
        'product_path' => "/product/test-{$sku}",
        'pack_quantity' => $packQuantity,
        'pack_unit' => $packUnit,
        'price_cents' => $priceCents,
        'available' => true,
        'status' => RetailerCandidateStatus::Eligible,
        'rejection_codes' => [],
        'label_evidence' => [],
        'fingerprint' => hash('sha256', implode('|', [$sku, $packQuantity, $packUnit, $priceCents])),
        'captured_at' => now(),
    ]);
}

it('applies the balanced price and waste boundary deterministically', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    $small = retailerCandidate($requirement, 'small', 'Penne 500g', 'penne', 500, 100);
    $lowWaste = retailerCandidate($requirement, 'low-waste', 'Penne 750g', 'penne', 750, 215);
    $chooser = app(ChooseBalancedPack::class);

    $withinBoundary = $chooser->handle($requirement, collect([$small, $lowWaste]));

    expect($withinBoundary['candidate']->is($lowWaste))->toBeTrue()
        ->and($withinBoundary['pack_count'])->toBe(1)
        ->and($withinBoundary['waste_quantity'])->toBe(150.0)
        ->and($withinBoundary['total_price_cents'])->toBe(215);

    $lowWaste->update(['price_cents' => 221]);
    $outsideBoundary = $chooser->handle(
        $requirement,
        collect([$small->refresh(), $lowWaste->refresh()]),
    );

    expect($outsideBoundary['candidate']->is($small))->toBeTrue()
        ->and($outsideBoundary['pack_count'])->toBe(2)
        ->and($outsideBoundary['total_price_cents'])->toBe(200);
});

it('chooses the smallest valid pack when the recipe quantity is unknown', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan'], 'salt', null, null);
    $large = retailerCandidate($requirement, 'large', 'Salt 1kg', 'salt', 1000, 150);
    $small = retailerCandidate($requirement, 'small', 'Salt 250g', 'salt', 250, 200);

    $choice = app(ChooseBalancedPack::class)->handle($requirement, collect([$large, $small]));

    expect($choice['candidate']->is($small))->toBeTrue()
        ->and($choice['pack_count'])->toBe(1)
        ->and($choice['waste_quantity'])->toBeNull();
});

it('rejects unavailable, restricted, cross-origin, and unproven safety candidates', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement(
        $workspace['grocery_plan'],
        'pasta',
        500,
        'g',
        [[
            'constraint_id' => 44,
            'kind' => 'allergy',
            'subject' => 'gluten',
        ]],
    );
    $validator = app(ValidateRetailerProductCandidate::class);
    $base = [
        'origin_host' => 'www.coles.com.au',
        'sku' => '3329035',
        'title' => 'Pasta 500g',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => 100,
        'available' => true,
    ];

    $unknown = $validator->handle($requirement, [
        ...$base,
        'label_evidence' => ['constraints' => []],
    ]);
    $conflict = $validator->handle($requirement, [
        ...$base,
        'label_evidence' => ['constraints' => [[
            'constraint_id' => 44,
            'status' => 'conflict',
            'source' => 'coles_product_label',
        ]]],
    ]);
    $compatible = $validator->handle($requirement, [
        ...$base,
        'label_evidence' => ['constraints' => [[
            'constraint_id' => 44,
            'status' => 'compatible',
            'source' => 'coles_product_label',
        ]]],
    ]);
    $restricted = $validator->handle($requirement, [
        ...$base,
        'restricted_product' => true,
        'label_evidence' => $compatible['label_evidence'],
    ]);
    $wrongOrigin = $validator->handle($requirement, [
        ...$base,
        'origin_host' => 'example.test',
        'label_evidence' => $compatible['label_evidence'],
    ]);

    expect($unknown['status'])->toBe(RetailerCandidateStatus::Rejected)
        ->and($unknown['rejection_codes'])->toContain('insufficient_constraint_evidence')
        ->and($conflict['rejection_codes'])->toContain('known_safety_conflict')
        ->and($restricted['rejection_codes'])->toContain('restricted_product')
        ->and($wrongOrigin['rejection_codes'])->toContain('wrong_origin')
        ->and($compatible['status'])->toBe(RetailerCandidateStatus::Eligible);
});

it('forces a low-confidence best guess only among candidates that passed every hard gate', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    $spirals = retailerCandidate($requirement, 'spirals', 'Pasta Spirals 500g', 'spirals', 500, 100);
    $spaghetti = retailerCandidate($requirement, 'spaghetti', 'Pasta Spaghetti 500g', 'spaghetti', 500, 90);
    $this->app->bind(RetailerProductRanker::class, fn () => new class($spaghetti->id, $spirals->id) implements RetailerProductRanker
    {
        public function __construct(private readonly int $first, private readonly int $second) {}

        public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
        {
            return new RetailerProductRankingResult([[
                'requirement_id' => $request->requirements[0]['requirement_id'],
                'ranked_candidate_ids' => [$this->first, $this->second],
                'confidence' => 0.2,
                'reason' => 'The recipe wording weakly favours a long pasta shape.',
            ]]);
        }
    });

    $selected = app(SelectRetailerProducts::class)->handle($workspace['grocery_plan']);
    $selection = $requirement->selection()->with('candidate')->sole();

    expect($selected)->toBeTrue()
        ->and($selection->candidate->is($spaghetti))->toBeTrue()
        ->and($selection->method)->toBe(RetailerSelectionMethod::AiRanked)
        ->and($selection->low_confidence)->toBeTrue()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::RevalidatingProducts)
        ->and($workspace['run']->items()->sole()->sku)->toBe('spaghetti');
});

it('finishes read-only discovery without changing or claiming to verify a basket', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    retailerCandidate($requirement, 'penne', 'Pasta Penne 500g', 'penne', 500, 110);
    config()->set('retailer.features.mutation', false);
    config()->set('retailer.features.mutation_circuit_breaker', true);

    (new SelectRetailerProductsJob($workspace['run']->id))->handle(
        app(SelectRetailerProducts::class),
    );

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::ProductsSelected)
        ->and($workspace['run']->basket_captured_at)->toBeNull()
        ->and($workspace['run']->failure_message)->toContain('existing basket was not changed');
});

it('reuses an explicit household product preference before invoking the ranker', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    retailerCandidate($requirement, 'spirals', 'Pasta Spirals 500g', 'spirals', 500, 100);
    $preferred = retailerCandidate($requirement, 'penne', 'Pasta Penne 500g', 'penne', 500, 110);
    RetailerProductPreference::query()->create([
        'team_id' => $workspace['team']->id,
        'created_by_user_id' => $workspace['user']->id,
        'provider' => RetailerProvider::Coles,
        'normalized_name' => $requirement->normalized_name,
        'normalized_form' => $requirement->normalized_form,
        'sku' => $preferred->sku,
        'product_title' => $preferred->title,
        'reason' => 'Always use this penne.',
    ]);
    $this->app->bind(RetailerProductRanker::class, fn () => new class implements RetailerProductRanker
    {
        public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
        {
            throw new RuntimeException('The ranker should not run for a saved preference.');
        }
    });

    app(SelectRetailerProducts::class)->handle($workspace['grocery_plan']);
    $selection = $requirement->selection()->with('candidate')->sole();

    expect($selection->candidate->is($preferred))->toBeTrue()
        ->and($selection->method)->toBe(RetailerSelectionMethod::SavedPreference);
});

it('blocks the whole run when any requirement has no valid candidate', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    retailerCandidate($requirement, 'rejected', 'Rejected pasta 500g', 'pasta', 500, 100)
        ->update(['status' => RetailerCandidateStatus::Rejected]);

    expect(app(SelectRetailerProducts::class)->handle($workspace['grocery_plan']))->toBeFalse()
        ->and($workspace['grocery_plan']->refresh()->status)->toBe(GroceryPlanStatus::NeedsProduct)
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsProduct)
        ->and($workspace['run']->items()->count())->toBe(0);
});

it('rejects ranking output that names anything except the discovered candidates', function () {
    $workspace = retailerSelectionWorkspace();
    $requirement = retailerRequirement($workspace['grocery_plan']);
    $first = retailerCandidate($requirement, 'spirals', 'Pasta Spirals 500g', 'spirals', 500, 100);
    retailerCandidate($requirement, 'spaghetti', 'Pasta Spaghetti 500g', 'spaghetti', 500, 90);
    $this->app->bind(RetailerProductRanker::class, fn () => new class($first->id) implements RetailerProductRanker
    {
        public function __construct(private readonly int $knownId) {}

        public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
        {
            return new RetailerProductRankingResult([[
                'requirement_id' => $request->requirements[0]['requirement_id'],
                'ranked_candidate_ids' => [$this->knownId, 999999],
                'confidence' => 1,
                'reason' => 'Invalid test response.',
            ]]);
        }
    });

    expect(fn () => app(SelectRetailerProducts::class)->handle($workspace['grocery_plan']))
        ->toThrow(ValidationException::class)
        ->and($requirement->selection()->count())->toBe(0);
});
