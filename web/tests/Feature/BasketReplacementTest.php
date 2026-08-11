<?php

use App\Actions\Baskets\ReplaceBasket;
use App\Actions\Baskets\RestoreBasket;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\RevokeRetailerAutomationGrant;
use App\Actions\Retailers\RuntimeRetailerMutationCircuitBreaker;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\BasketSnapshotKind;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerSelectionMethod;
use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\RetailerConnection;
use App\Models\RetailerProductCandidate;
use App\Models\RetailerProductSelection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Illuminate\Support\Str;
use Tests\Support\MockExpectation;

/**
 * @return array{
 *     user: User,
 *     team: Team,
 *     connection: RetailerConnection,
 *     grocery_plan: GroceryPlan,
 *     requirement: GroceryRequirement,
 *     candidate: RetailerProductCandidate,
 *     selection: RetailerProductSelection,
 *     run: BasketRun,
 *     gateway: FakeRetailerAutomationGateway
 * }
 */
function basketReplacementWorkspace(): array
{
    config()->set('retailer.features.mutation', true);
    config()->set('retailer.features.mutation_circuit_breaker', false);
    config()->set('retailer.runtime_circuit_breaker.store', 'array');
    app(RuntimeRetailerMutationCircuitBreaker::class)->close('basket replacement test');

    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Basket replacement family');
    $mealPlan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'context_test',
        'context_lookup_hash' => hash('sha256', 'context_test'),
        'authenticated_at' => now(),
        'last_verified_at' => now(),
    ]);
    $connection->grants()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'scope' => RetailerAutomationScope::ReplaceBasketAfterPlanApproval,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
        'disclosure_hash' => hash('sha256', config('retailer.consent.disclosure')),
        'granted_at' => now(),
    ]);
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', "grocery-{$mealPlan->id}"),
        'recipe_fingerprint' => hash('sha256', "recipes-{$mealPlan->id}"),
        'built_at' => now(),
    ]);
    $requirement = $groceryPlan->requirements()->create([
        'team_id' => $team->id,
        'status' => GroceryRequirementStatus::Selected,
        'display_name' => 'Pasta',
        'normalized_name' => 'pasta',
        'quantity' => 800,
        'unit' => 'g',
        'quantity_unknown' => false,
        'fingerprint' => hash('sha256', "requirement-{$mealPlan->id}"),
        'search_queries' => ['penne pasta', 'pasta'],
        'applicable_constraints' => [],
    ]);
    $candidate = $requirement->candidates()->create([
        'team_id' => $team->id,
        'provider' => RetailerProvider::Coles,
        'sku' => '3329035',
        'title' => 'Penne Pasta 500g',
        'brand' => 'Coles',
        'semantic_key' => 'penne pasta',
        'origin_host' => 'www.coles.com.au',
        'product_path' => '/product/penne-pasta-3329035',
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => 150,
        'available' => true,
        'status' => RetailerCandidateStatus::Eligible,
        'rejection_codes' => [],
        'label_evidence' => [],
        'fingerprint' => hash('sha256', "candidate-{$mealPlan->id}"),
        'captured_at' => now(),
    ]);
    $selection = $requirement->selection()->create([
        'team_id' => $team->id,
        'retailer_product_candidate_id' => $candidate->id,
        'method' => RetailerSelectionMethod::Deterministic,
        'confidence' => 1,
        'low_confidence' => false,
        'reasoning' => 'Two 500 g packs cover the requirement at the lowest valid cost.',
        'pack_count' => 2,
        'required_quantity' => 800,
        'total_quantity' => 1000,
        'waste_quantity' => 200,
        'total_price_cents' => 300,
        'selection_checksum' => hash('sha256', "selection-{$mealPlan->id}"),
        'selected_at' => now(),
    ]);
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection->id,
        'requested_by_user_id' => $user->id,
        'status' => BasketRunStatus::RevalidatingProducts,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', "basket-{$mealPlan->id}"),
        'target_checksum' => hash('sha256', 'target'),
        'chef_subtotal_cents' => 300,
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
    ]);
    $gateway = app(FakeRetailerAutomationGateway::class);
    $gateway->searchCandidates = [[
        'requirement_id' => $requirement->id,
        'origin_host' => 'www.coles.com.au',
        'sku' => $candidate->sku,
        'title' => $candidate->title,
        'brand' => $candidate->brand,
        'semantic_key' => $candidate->semantic_key,
        'product_path' => $candidate->product_path,
        'pack_quantity' => $candidate->pack_quantity,
        'pack_unit' => $candidate->pack_unit,
        'price_cents' => $candidate->price_cents,
        'available' => true,
        'restricted_product' => false,
        'label_evidence' => [],
    ]];

    return compact(
        'user',
        'team',
        'connection',
        'groceryPlan',
        'requirement',
        'candidate',
        'selection',
        'run',
        'gateway',
    ) + ['grocery_plan' => $groceryPlan];
}

/** @return array<string, mixed> */
function basketLine(
    string $sku,
    string $title,
    int $quantity,
    int $unitPriceCents,
): array {
    return [
        'sku' => $sku,
        'title' => $title,
        'absolute_quantity' => $quantity,
        'unit_price_cents' => $unitPriceCents,
        'line_price_cents' => $quantity * $unitPriceCents,
    ];
}

/** @param array<string, mixed> $data */
function basketWorkerResult(
    RetailerWorkerResultStatus $status,
    array $data = [],
    ?string $reasonCode = null,
): RetailerWorkerResult {
    return new RetailerWorkerResult(
        status: $status,
        reasonCode: $reasonCode,
        verificationChecksum: $status === RetailerWorkerResultStatus::Succeeded
            ? hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))
            : null,
        data: $data,
    );
}

it('replaces a non-empty basket and persists verified baseline and final snapshots', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 3, 100),
    ];

    $replaced = app(ReplaceBasket::class)->handle(
        $workspace['run'],
        $workspace['gateway'],
    );
    $run = $workspace['run']->refresh();

    expect($replaced)->toBeTrue()
        ->and($run->status)->toBe(BasketRunStatus::Ready)
        ->and($run->replaced_line_count)->toBe(1)
        ->and($run->chef_subtotal_cents)->toBe(300)
        ->and($run->retailer_total_cents)->toBe(300)
        ->and($run->claim_token)->toBeNull()
        ->and($run->claimed_at)->toBeNull()
        ->and($run->items()->sole()->verified_at)->not->toBeNull()
        ->and($workspace['gateway']->basketLines)->toHaveCount(1)
        ->and($workspace['gateway']->basketLines[0]['sku'])->toBe('3329035')
        ->and($workspace['gateway']->basketLines[0]['absolute_quantity'])->toBe(2)
        ->and($run->snapshots()->where('kind', BasketSnapshotKind::Baseline)->sole()->line_count)->toBe(1)
        ->and($run->snapshots()->where('kind', BasketSnapshotKind::Final)->sole()->line_count)->toBe(1);
});

it('refuses replacement and restoration without PostgreSQL and Redis outside tests', function () {
    $workspace = basketReplacementWorkspace();
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('database.default', 'sqlite');
    config()->set('queue.default', 'database');
    config()->set('cache.default', 'database');

    expect(fn () => app(ReplaceBasket::class)->handle(
        $workspace['run'],
        $workspace['gateway'],
    ))->toThrow(RuntimeException::class, 'Live retailer automation requires PostgreSQL and Redis.')
        ->and(fn () => app(RestoreBasket::class)->handle(
            $workspace['run'],
            $workspace['gateway'],
        ))->toThrow(RuntimeException::class, 'Live retailer automation requires PostgreSQL and Redis.')
        ->and($workspace['gateway']->commands)->toBe([]);
});

it('inspects after lost mutation responses instead of repeating successful effects', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 1, 100),
    ];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketEmpty,
        basketWorkerResult(RetailerWorkerResultStatus::Retryable, reasonCode: 'response_lost'),
        [],
    );
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Retryable, reasonCode: 'response_lost'),
        [basketLine('3329035', 'Penne Pasta 500g', 2, 150)],
    );

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeTrue()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Ready)
        ->and(collect($workspace['gateway']->commands)->where('command', RetailerWorkerCommand::EnsureBasketEmpty))->toHaveCount(1)
        ->and(collect($workspace['gateway']->commands)->where('command', RetailerWorkerCommand::EnsureBasketLine))->toHaveCount(1);
});

it('stops mutation and preserves the revoked state when standing consent is withdrawn mid-replacement', function () {
    $workspace = basketReplacementWorkspace();
    $gateway = new class($workspace['connection'], $workspace['user']) extends FakeRetailerAutomationGateway
    {
        private bool $basketWasCleared = false;

        private bool $consentWasRevoked = false;

        public function __construct(
            private readonly RetailerConnection $connection,
            private readonly User $user,
        ) {
            parent::__construct();
        }

        public function execute(
            string $contextId,
            RetailerWorkerCommand $command,
            array $payload = [],
            ?string $sessionId = null,
        ): RetailerWorkerResult {
            $result = parent::execute($contextId, $command, $payload, $sessionId);

            if ($command === RetailerWorkerCommand::EnsureBasketEmpty) {
                $this->basketWasCleared = true;
            } elseif ($command === RetailerWorkerCommand::InspectBasket
                && $this->basketWasCleared
                && ! $this->consentWasRevoked) {
                $this->consentWasRevoked = true;
                app(RevokeRetailerAutomationGrant::class)->handle($this->connection, $this->user);
            }

            return $result;
        }
    };
    $gateway->basketLines = [basketLine('old-apple', 'Apples', 1, 100)];
    $gateway->searchCandidates = $workspace['gateway']->searchCandidates;

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $gateway))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsAttention)
        ->and($workspace['run']->failure_code)->toBe('standing_consent_revoked_during_mutation')
        ->and($workspace['run']->claim_token)->toBeNull()
        ->and($workspace['connection']->grants()->whereNull('revoked_at')->count())->toBe(0)
        ->and(collect($gateway->commands)->where('command', RetailerWorkerCommand::EnsureBasketLine))->toHaveCount(0)
        ->and($gateway->basketLines)->toBe([]);
});

it('stops before mutation when the basket changes after the baseline inspection', function () {
    $workspace = basketReplacementWorkspace();
    $baseline = [
        'lines' => [basketLine('old-apple', 'Apples', 1, 100)],
        'retailer_total_cents' => 100,
    ];
    $concurrent = [
        'lines' => [
            basketLine('old-apple', 'Apples', 1, 100),
            basketLine('user-edit', 'User added milk', 1, 250),
        ],
        'retailer_total_cents' => 350,
    ];
    $workspace['gateway']->basketLines = $baseline['lines'];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::InspectBasket,
        basketWorkerResult(RetailerWorkerResultStatus::Succeeded, $baseline),
    );
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::InspectBasket,
        basketWorkerResult(RetailerWorkerResultStatus::Succeeded, $concurrent),
        $concurrent['lines'],
    );

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Uncertain)
        ->and(collect($workspace['gateway']->commands)->where('command', RetailerWorkerCommand::EnsureBasketEmpty))->toHaveCount(0)
        ->and($workspace['gateway']->basketLines)->toHaveCount(2);
});

it('leaves concurrent user additions uncertain instead of restoring over them', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 1, 100),
    ];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Succeeded, [
            'product_id' => '3329035',
            'absolute_quantity' => 2,
        ]),
        [
            basketLine('3329035', 'Penne Pasta 500g', 2, 150),
            basketLine('user-edit', 'User added milk', 1, 250),
        ],
    );

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Uncertain)
        ->and($workspace['run']->snapshots()->where('kind', BasketSnapshotKind::Restoration)->count())->toBe(0)
        ->and($workspace['gateway']->basketLines)->toHaveCount(2);
});

it('restores the baseline automatically when replacement fails after clearing', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 3, 100),
    ];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Failed, reasonCode: 'exact_product_unavailable'),
    );

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::Restored)
        ->and($workspace['gateway']->basketLines)->toHaveCount(1)
        ->and($workspace['gateway']->basketLines[0]['sku'])->toBe('old-apple')
        ->and($workspace['gateway']->basketLines[0]['absolute_quantity'])->toBe(3)
        ->and($workspace['run']->snapshots()->where('kind', BasketSnapshotKind::Restoration)->sole()->line_count)->toBe(1);
});

it('marks incomplete restoration as needs attention', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 3, 100),
    ];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Failed, reasonCode: 'replacement_failed'),
    );
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Failed, reasonCode: 'restoration_failed'),
    );

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsAttention)
        ->and($workspace['run']->failure_code)->toBe('restoration_incomplete')
        ->and($workspace['gateway']->basketLines)->toBe([]);
});

it('marks the basket as needing attention when the circuit breaker pauses restoration', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 3, 100),
    ];
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::EnsureBasketLine,
        basketWorkerResult(RetailerWorkerResultStatus::Failed, reasonCode: 'replacement_failed'),
    );
    $restoreBasket = Mockery::mock(RestoreBasket::class);
    MockExpectation::for($restoreBasket, 'handle')
        ->once()
        ->andThrow(new RuntimeException('Retailer basket mutation is disabled.'));
    app()->instance(RestoreBasket::class, $restoreBasket);

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsAttention)
        ->and($workspace['run']->failure_code)->toBe('restoration_paused')
        ->and($workspace['gateway']->basketLines)->toBe([]);
});

it('requires authentication and does not inspect or mutate the basket when the Context expires', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->authenticated = false;
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 1, 100),
    ];

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::ReauthenticationRequired)
        ->and($workspace['run']->snapshots()->count())->toBe(0)
        ->and(collect($workspace['gateway']->commands)->pluck('command'))->not->toContain(RetailerWorkerCommand::InspectBasket)
        ->and($workspace['gateway']->basketLines[0]['sku'])->toBe('old-apple');
});

it('leaves the original basket untouched when revalidation no longer passes hard gates', function () {
    $workspace = basketReplacementWorkspace();
    $workspace['gateway']->basketLines = [
        basketLine('old-apple', 'Apples', 1, 100),
    ];
    $workspace['gateway']->searchCandidates[0]['available'] = false;

    expect(app(ReplaceBasket::class)->handle($workspace['run'], $workspace['gateway']))
        ->toBeFalse()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::NeedsProduct)
        ->and($workspace['run']->snapshots()->count())->toBe(0)
        ->and($workspace['gateway']->basketLines[0]['sku'])->toBe('old-apple')
        ->and(collect($workspace['gateway']->commands)->pluck('command'))->not->toContain(RetailerWorkerCommand::EnsureBasketEmpty);
});
