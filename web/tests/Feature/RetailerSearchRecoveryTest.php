<?php

use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\ReleaseRetailerLiveSession;
use App\Actions\Retailers\RevokeRetailerAutomationGrant;
use App\Actions\Retailers\ValidateRetailerProductCandidate;
use App\Actions\Teams\CreateTeamForUser;
use App\Ai\Contracts\RetailerSearchRecovery;
use App\Ai\Data\RetailerSearchRecoveryRequest;
use App\Ai\Data\RetailerSearchRecoveryResult;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\GroceryRequirementStatus;
use App\Enums\GrocerySearchMethod;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\ProbeRetailerConnectionJob;
use App\Jobs\SelectRetailerProductsJob;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\GroceryRequirement;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @return array{user: User, team: Team, grocery_plan: GroceryPlan, requirement: GroceryRequirement, connection: RetailerConnection, run: BasketRun, gateway: FakeRetailerAutomationGateway} */
function searchRecoveryWorkspace(): array
{
    Queue::fake();
    config()->set('retailer.features.discovery', true);
    config()->set('retailer.features.ai_recovery', true);
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Search recovery family');
    $mealPlan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'context-search-recovery',
        'context_lookup_hash' => hash('sha256', 'context-search-recovery'),
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
        'input_fingerprint' => hash('sha256', 'recovery-grocery-'.Str::uuid()),
        'recipe_fingerprint' => hash('sha256', 'recovery-recipes-'.Str::uuid()),
        'built_at' => now(),
    ]);
    $requirement = searchRecoveryRequirement($groceryPlan, 'pasta');
    $run = BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'retailer_connection_id' => $connection->id,
        'requested_by_user_id' => $user->id,
        'status' => BasketRunStatus::DiscoveringProducts,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', 'recovery-run-'.Str::uuid()),
    ]);
    $gateway = new FakeRetailerAutomationGateway;

    return compact('user', 'team', 'groceryPlan', 'requirement', 'connection', 'run', 'gateway') + [
        'grocery_plan' => $groceryPlan,
    ];
}

function searchRecoveryRequirement(GroceryPlan $groceryPlan, string $name): GroceryRequirement
{
    return $groceryPlan->requirements()->create([
        'team_id' => $groceryPlan->team_id,
        'status' => GroceryRequirementStatus::Pending,
        'display_name' => Str::headline($name),
        'normalized_name' => $name,
        'normalized_form' => 'dry',
        'quantity' => 500,
        'unit' => 'g',
        'quantity_unknown' => false,
        'fingerprint' => hash('sha256', 'recovery-requirement-'.$name.Str::uuid()),
        'search_queries' => ["{$name} dry", $name, "plain {$name}"],
        'applicable_constraints' => [],
    ]);
}

/** @param list<array<string, mixed>> $candidates */
function searchRecoveryWorkerResult(array $candidates): RetailerWorkerResult
{
    return new RetailerWorkerResult(
        RetailerWorkerResultStatus::Succeeded,
        null,
        hash('sha256', json_encode($candidates, JSON_THROW_ON_ERROR)),
        ['candidates' => $candidates],
    );
}

/** @return array<string, mixed> */
function recoveredCandidate(GroceryRequirement $requirement, string $sku): array
{
    return [
        'requirement_id' => $requirement->id,
        'origin_host' => 'www.coles.com.au',
        'sku' => $sku,
        'title' => 'Penne Pasta 500g',
        'brand' => 'Coles',
        'semantic_key' => 'penne',
        'product_path' => '/product/'.$sku,
        'pack_quantity' => 500,
        'pack_unit' => 'g',
        'price_cents' => 150,
        'available' => true,
        'restricted_product' => false,
        'label_evidence' => [],
    ];
}

it('uses one validated AI query only after two deterministic searches and persists every attempt', function () {
    $workspace = searchRecoveryWorkspace();
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::SearchProducts,
        searchRecoveryWorkerResult([recoveredCandidate($workspace['requirement'], 'recovered-penne')]),
    );
    $this->app->bind(RetailerSearchRecovery::class, fn () => new class implements RetailerSearchRecovery
    {
        public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
        {
            return new RetailerSearchRecoveryResult([[
                'requirement_id' => $request->requirements[0]['requirement_id'],
                'query' => 'twisted pasta',
            ]]);
        }
    });

    app()->call([new DiscoverRetailerProductsJob($workspace['run']->id), 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    $attempts = $workspace['requirement']->searchAttempts()->orderBy('sequence')->get();
    expect($attempts)->toHaveCount(3)
        ->and($attempts->pluck('method')->all())->toBe([
            GrocerySearchMethod::Deterministic,
            GrocerySearchMethod::Deterministic,
            GrocerySearchMethod::AiRecovery,
        ])
        ->and($attempts->pluck('query')->all())->toBe(['pasta dry', 'pasta', 'twisted pasta'])
        ->and($workspace['requirement']->candidates()->sole()->sku)->toBe('recovered-penne')
        ->and(collect($workspace['gateway']->commands)->where('command', RetailerWorkerCommand::SearchProducts))->toHaveCount(2);
    Queue::assertPushed(SelectRetailerProductsJob::class, 1);
});

it('does not discover products while a Live View session is active or opening', function (array $actorState) {
    $workspace = searchRecoveryWorkspace();
    $workspace['connection']->update([
        ...$actorState,
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(5),
    ]);

    app()->call([new DiscoverRetailerProductsJob($workspace['run']->id), 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    expect($workspace['gateway']->commands)->toBeEmpty()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::DiscoveringProducts)
        ->and($workspace['run']->claim_token)->toBeNull();
})->with([
    'active session' => [[
        'active_session_id' => 'session-review',
        'active_session_claim_token' => 'claim-review',
    ]],
    'opening claim' => [[
        'active_session_id' => null,
        'active_session_claim_token' => 'claim-opening',
    ]],
]);

it('resumes deferred discovery after the owner releases Live View', function () {
    $workspace = searchRecoveryWorkspace();
    $workspace['connection']->update([
        'active_session_id' => 'session-review',
        'active_session_claim_token' => 'claim-review',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(5),
    ]);

    app(ReleaseRetailerLiveSession::class)->handle(
        $workspace['connection']->refresh(),
        $workspace['user'],
        $workspace['gateway'],
    );

    Queue::assertPushed(
        DiscoverRetailerProductsJob::class,
        fn (DiscoverRetailerProductsJob $job): bool => $job->basketRunId === $workspace['run']->id,
    );
    Queue::assertPushed(
        ProbeRetailerConnectionJob::class,
        fn (ProbeRetailerConnectionJob $job): bool => $job->basketRunId === $workspace['run']->id,
    );
});

it('does not revive discovery when consent is revoked during a retailer search', function () {
    $workspace = searchRecoveryWorkspace();
    $gateway = new class($workspace['connection'], $workspace['user']) extends FakeRetailerAutomationGateway
    {
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

            if ($command === RetailerWorkerCommand::SearchProducts) {
                app(RevokeRetailerAutomationGrant::class)->handle($this->connection, $this->user);
            }

            return $result;
        }
    };
    $gateway->searchCandidates = [recoveredCandidate($workspace['requirement'], 'revoked-penne')];

    app()->call([new DiscoverRetailerProductsJob($workspace['run']->id), 'handle'], [
        'gateway' => $gateway,
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    expect($workspace['run']->refresh()->status)->toBe(BasketRunStatus::WaitingForConnection)
        ->and($workspace['run']->claim_token)->toBeNull()
        ->and($workspace['requirement']->candidates()->count())->toBe(0);
    Queue::assertNotPushed(SelectRetailerProductsJob::class);
});

it('falls back to the deterministic broad query when AI returns an invalid URL or identifier', function (array $queries) {
    $workspace = searchRecoveryWorkspace();
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $recoveryQueries = [];
    foreach ($queries as $query) {
        if (! is_array($query)
            || ! is_int($query['requirement_id'] ?? null)
            || ! is_string($query['query'] ?? null)) {
            throw new RuntimeException('The recovery test fixture must match the worker contract.');
        }
        $recoveryQueries[] = [
            'requirement_id' => $query['requirement_id'],
            'query' => $query['query'],
        ];
    }
    $this->app->bind(RetailerSearchRecovery::class, fn () => new class($recoveryQueries) implements RetailerSearchRecovery
    {
        /** @param list<array{requirement_id: int, query: string}> $queries */
        public function __construct(private readonly array $queries) {}

        public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
        {
            return new RetailerSearchRecoveryResult($this->queries);
        }
    });

    app()->call([new DiscoverRetailerProductsJob($workspace['run']->id), 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    $attempts = $workspace['requirement']->searchAttempts()->orderBy('sequence')->get();
    expect($attempts)->toHaveCount(3)
        ->and($attempts->last()->method)->toBe(GrocerySearchMethod::DeterministicFallback)
        ->and($attempts->last()->query)->toBe('plain pasta')
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::SelectingProducts);
})->with([
    'URL' => [[['requirement_id' => 1, 'query' => 'https://coles.com.au/search']]],
    'unknown identifier' => [[['requirement_id' => 999999, 'query' => 'pasta shapes']]],
]);

it('batches all unresolved existing requirements into one recovery invocation', function () {
    $workspace = searchRecoveryWorkspace();
    $rice = searchRecoveryRequirement($workspace['grocery_plan'], 'rice');
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([
        recoveredCandidate($workspace['requirement'], 'pasta-final'),
        recoveredCandidate($rice, 'rice-final'),
    ]));
    $recovery = new class implements RetailerSearchRecovery
    {
        public int $calls = 0;

        /** @var list<int> */
        public array $requirementIds = [];

        public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
        {
            $this->calls++;
            $this->requirementIds = array_column($request->requirements, 'requirement_id');

            return new RetailerSearchRecoveryResult(array_map(
                fn (array $requirement): array => [
                    'requirement_id' => $requirement['requirement_id'],
                    'query' => 'broader '.$requirement['name'],
                ],
                $request->requirements,
            ));
        }
    };
    $this->app->instance(RetailerSearchRecovery::class, $recovery);

    app()->call([new DiscoverRetailerProductsJob($workspace['run']->id), 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    expect($recovery->calls)->toBe(1)
        ->and($recovery->requirementIds)->toBe([$workspace['requirement']->id, $rice->id])
        ->and($workspace['grocery_plan']->requirements()->whereHas('candidates')->count())->toBe(2);
});

it('never exceeds three searches and does not repeat retailer calls when the job is replayed', function () {
    $workspace = searchRecoveryWorkspace();
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $workspace['gateway']->queueResult(RetailerWorkerCommand::SearchProducts, searchRecoveryWorkerResult([]));
    $this->app->bind(RetailerSearchRecovery::class, fn () => new class implements RetailerSearchRecovery
    {
        public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
        {
            throw new RuntimeException('Provider unavailable');
        }
    });
    $job = new DiscoverRetailerProductsJob($workspace['run']->id);

    app()->call([$job, 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);
    $commandCount = count($workspace['gateway']->commands);
    app()->call([$job, 'handle'], [
        'gateway' => $workspace['gateway'],
        'validateCandidate' => app(ValidateRetailerProductCandidate::class),
    ]);

    expect($workspace['requirement']->searchAttempts()->count())->toBe(3)
        ->and(count($workspace['gateway']->commands))->toBe($commandCount);
});

it('records a safe terminal failure after product discovery exhausts its retries', function () {
    $workspace = searchRecoveryWorkspace();
    $workspace['run']->update([
        'status' => BasketRunStatus::DiscoveringProducts,
        'claim_token' => 'failed-discovery-claim',
        'claimed_at' => now(),
    ]);

    (new DiscoverRetailerProductsJob($workspace['run']->id))
        ->failed(new RuntimeException('provider secret must not persist'));

    $run = $workspace['run']->refresh();
    expect($run->status)->toBe(BasketRunStatus::Failed)
        ->and($run->failure_code)->toBe('product_discovery_failed')
        ->and($run->failure_message)->toBe('Chef stopped after it could not finish finding Coles products. Try preparing the basket again.')
        ->and($run->failure_message)->not->toContain('provider secret')
        ->and($run->claim_token)->toBeNull()
        ->and($run->claimed_at)->toBeNull();
});

it('records a safe terminal failure after product selection exhausts its retries', function () {
    $workspace = searchRecoveryWorkspace();
    $workspace['run']->update([
        'status' => BasketRunStatus::SelectingProducts,
        'claim_token' => 'failed-selection-claim',
        'claimed_at' => now(),
    ]);

    (new SelectRetailerProductsJob($workspace['run']->id))
        ->failed(new RuntimeException('ranking secret must not persist'));

    $run = $workspace['run']->refresh();
    expect($run->status)->toBe(BasketRunStatus::Failed)
        ->and($run->failure_code)->toBe('product_selection_failed')
        ->and($run->failure_message)->toBe('Chef stopped after it could not finish choosing Coles products. Try preparing the basket again.')
        ->and($run->failure_message)->not->toContain('ranking secret')
        ->and($run->claim_token)->toBeNull()
        ->and($run->claimed_at)->toBeNull();
});
