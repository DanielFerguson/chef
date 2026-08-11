<?php

use App\Actions\Baskets\StartBasketRunForApprovedPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\DisconnectRetailerConnection;
use App\Actions\Retailers\StartRetailerConnection;
use App\Actions\Retailers\VerifyRetailerConnection;
use App\Actions\Teams\AddUserToTeam;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\GroceryPlanStatus;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerWorkerCommand;
use App\Models\BasketRun;
use App\Models\GroceryPlan;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerLiveSession;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/** @return array{user: User, team: Team, gateway: FakeRetailerAutomationGateway} */
function retailerConnectionWorkspace(): array
{
    Queue::fake();
    config()->set('retailer.features.experience', true);
    config()->set('retailer.features.discovery', false);
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Connected family');
    $gateway = new FakeRetailerAutomationGateway;
    app()->instance(RetailerAutomationGateway::class, $gateway);

    return compact('user', 'team', 'gateway');
}

function createWaitingBasketRun(Team $team, User $user): BasketRun
{
    $mealPlan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $groceryPlan = GroceryPlan::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'version' => 1,
        'status' => GroceryPlanStatus::Ready,
        'input_fingerprint' => hash('sha256', "connection-grocery-{$mealPlan->id}"),
        'recipe_fingerprint' => hash('sha256', "connection-recipes-{$mealPlan->id}"),
        'built_at' => now(),
    ]);

    return BasketRun::query()->create([
        'team_id' => $team->id,
        'meal_plan_id' => $mealPlan->id,
        'grocery_plan_id' => $groceryPlan->id,
        'requested_by_user_id' => $user->id,
        'status' => BasketRunStatus::WaitingForConnection,
        'idempotency_key' => Str::uuid(),
        'input_fingerprint' => hash('sha256', "connection-run-{$mealPlan->id}"),
    ]);
}

it('creates a just-in-time Context while keeping sensitive capabilities encrypted and hidden', function () {
    $workspace = retailerConnectionWorkspace();

    $response = $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections');

    $response->assertCreated()
        ->assertJsonPath('connection.status', RetailerConnectionStatus::PendingAuthentication->value)
        ->assertJsonPath('consent.version', config('retailer.consent.disclosure_version'))
        ->assertJsonPath('session.live_view_url', fn ($value): bool => is_string($value)
            && str_starts_with($value, 'https://live.example.test/'));
    $connection = RetailerConnection::query()->sole();
    $raw = DB::table('retailer_connections')->where('id', $connection->id)->first();

    expect($connection->browserbase_context_id)->toStartWith('context_')
        ->and($connection->active_session_id)->toStartWith('session_')
        ->and($raw->browserbase_context_id)->not->toBe($connection->browserbase_context_id)
        ->and($raw->active_session_id)->not->toBe($connection->active_session_id)
        ->and(json_encode($raw, JSON_THROW_ON_ERROR))->not->toContain('live.example.test')
        ->and($connection->toArray())->not->toHaveKeys([
            'browserbase_context_id',
            'context_lookup_hash',
            'active_session_id',
            'active_session_claim_token',
        ]);
});

it('serializes Context provisioning so concurrent connection attempts cannot replace or delete the active Context', function () {
    $workspace = retailerConnectionWorkspace();
    $gateway = new class extends FakeRetailerAutomationGateway
    {
        public int $createdContextCount = 0;

        public ?Closure $duringFirstCreate = null;

        public function createContext(): string
        {
            $this->createdContextCount++;
            $callback = $this->duringFirstCreate;
            $this->duringFirstCreate = null;
            $callback?->__invoke();

            return parent::createContext();
        }
    };
    $gateway->duringFirstCreate = function () use ($workspace, $gateway): void {
        expect(fn () => app(StartRetailerConnection::class)->handle(
            $workspace['team'],
            $workspace['user'],
            $gateway,
        ))->toThrow(ValidationException::class);
    };

    $result = app(StartRetailerConnection::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $gateway,
    );
    $connection = $result['connection']->refresh();

    expect($gateway->createdContextCount)->toBe(1)
        ->and($gateway->deletedContexts)->toBe([])
        ->and($connection->browserbase_context_id)->toStartWith('context_')
        ->and($connection->active_session_id)->toStartWith('session_')
        ->and($connection->active_session_claim_token)->not->toBeNull();
});

it('clears its provisioning claim when a newly created Context loses the connection race', function () {
    $workspace = retailerConnectionWorkspace();
    $gateway = new class extends FakeRetailerAutomationGateway
    {
        public function createContext(): string
        {
            RetailerConnection::query()->sole()->update([
                'status' => RetailerConnectionStatus::Disconnected,
                'disconnected_at' => now(),
            ]);

            return parent::createContext();
        }
    };

    expect(fn () => app(StartRetailerConnection::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $gateway,
    ))->toThrow(ValidationException::class);

    $connection = RetailerConnection::query()->sole();

    expect($gateway->deletedContexts)->toHaveCount(1)
        ->and($connection->status)->toBe(RetailerConnectionStatus::Disconnected)
        ->and($connection->browserbase_context_id)->toBeNull()
        ->and($connection->active_session_claim_token)->toBeNull()
        ->and($connection->active_session_purpose)->toBeNull();
});

it('verifies authentication, records standing consent, and resumes the pending basket automatically', function () {
    $workspace = retailerConnectionWorkspace();
    $run = createWaitingBasketRun($workspace['team'], $workspace['user']);
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');

    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => true,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
    ])->assertOk()
        ->assertJsonPath('connection.status', RetailerConnectionStatus::Connected->value)
        ->assertJsonPath('connection.standing_consent', true);

    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $grant = $connection->grants()->sole();

    expect($connection->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($connection->active_session_id)->toBeNull()
        ->and($connection->last_verified_at)->not->toBeNull()
        ->and($grant->scope)->toBe(RetailerAutomationScope::ReplaceBasketAfterPlanApproval)
        ->and($grant->owner_user_id)->toBe($workspace['user']->id)
        ->and($grant->disclosure_version)->toBe(config('retailer.consent.disclosure_version'))
        ->and($grant->disclosure_hash)->toBe(hash('sha256', config('retailer.consent.disclosure')))
        ->and($grant->revoked_at)->toBeNull()
        ->and($run->refresh()->retailer_connection_id)->toBe($connection->id)
        ->and($run->status)->toBe(BasketRunStatus::DiscoveringProducts);
});

it('does not grant standing consent until the exact disclosure is accepted and authentication is verified', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');

    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => false,
        'disclosure_version' => 'outdated-disclosure',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['standing_consent', 'disclosure_version']);

    $workspace['gateway']->authenticated = false;
    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => true,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
    ])->assertUnprocessable();

    expect(RetailerConnection::query()->findOrFail($connectionId)->status)
        ->toBe(RetailerConnectionStatus::PendingAuthentication)
        ->and(RetailerConnection::query()->findOrFail($connectionId)->grants()->count())
        ->toBe(0);
});

it('permits only one active Live View session for a retailer Context', function () {
    $workspace = retailerConnectionWorkspace();
    $firstSession = $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('session.live_view_url');
    $connection = RetailerConnection::query()->sole();
    $firstSessionId = $connection->active_session_id;
    $secondSession = $this->postJson('/retailer-connections')
        ->assertCreated()
        ->json('session.live_view_url');

    expect($secondSession)->not->toBe($firstSession)
        ->and($connection->refresh()->active_session_id)->not->toBe($firstSessionId)
        ->and(collect($workspace['gateway']->commands)
            ->where('command', RetailerWorkerCommand::ReleaseSession))
        ->toHaveCount(1);

    $connection->update([
        'active_session_id' => null,
        'active_session_claim_token' => Str::uuid(),
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinute(),
    ]);
    $this->postJson('/retailer-connections')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection');
});

it('relays bounded ephemeral mobile input only for the active session owner without echoing it', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $secret = 'not-saved-password';

    Sanctum::actingAs($workspace['user']);
    $this->postJson("/api/v1/retailer-connections/{$connectionId}/live-input", [
        'text' => $secret,
    ])->assertOk()
        ->assertExactJson([
            'forwarded' => true,
            'kind' => 'text',
        ])
        ->assertDontSee($secret);
    $this->postJson("/api/v1/retailer-connections/{$connectionId}/live-input", [
        'key' => 'Enter',
    ])->assertOk()
        ->assertExactJson([
            'forwarded' => true,
            'kind' => 'key',
        ]);

    expect($workspace['gateway']->relayedInputs)->toHaveCount(2)
        ->and($workspace['gateway']->relayedInputs[0]['value'])->toBe($secret)
        ->and($workspace['gateway']->relayedInputs[1]['value'])->toBe('Enter');

    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();
    Sanctum::actingAs($member);
    $this->postJson("/api/v1/retailer-connections/{$connectionId}/live-input", [
        'text' => 'must-not-forward',
    ])->assertForbidden();

    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $connection->update(['active_session_expires_at' => now()->subSecond()]);
    Sanctum::actingAs($workspace['user']);
    $this->postJson("/api/v1/retailer-connections/{$connectionId}/live-input", [
        'key' => 'Enter',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('connection');
});

it('releases the owner Live View capability and clears the active session', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');

    Sanctum::actingAs($workspace['user']);
    $this->deleteJson("/api/v1/retailer-connections/{$connectionId}/live-session")
        ->assertOk()
        ->assertExactJson(['released' => true]);

    $connection = RetailerConnection::query()->findOrFail($connectionId);
    expect($connection->active_session_id)->toBeNull()
        ->and($connection->active_session_purpose)->toBeNull()
        ->and(collect($workspace['gateway']->commands)
            ->where('command', RetailerWorkerCommand::ReleaseSession))
        ->toHaveCount(1);

    $this->deleteJson("/api/v1/retailer-connections/{$connectionId}/live-session")
        ->assertOk()
        ->assertExactJson(['released' => true]);
});

it('keeps verification, disconnection, and Live View owner-only with cross-team isolation', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $member = User::factory()->create();
    app(AddUserToTeam::class)->handle($workspace['team'], $member);
    $member->forceFill(['current_team_id' => $workspace['team']->id])->save();

    $this->actingAs($member)
        ->postJson("/retailer-connections/{$connectionId}/verify", [
            'standing_consent' => true,
            'disclosure_version' => config('retailer.consent.disclosure_version'),
        ])
        ->assertForbidden();
    $this->deleteJson("/retailer-connections/{$connectionId}")
        ->assertForbidden();

    $outsider = User::factory()->create();
    app(CreateTeamForUser::class)->handle($outsider, 'Other family');
    $this->actingAs($outsider)
        ->deleteJson("/retailer-connections/{$connectionId}")
        ->assertNotFound();
});

it('revokes standing consent immediately and deletes the hosted Context on disconnect', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => true,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
    ])->assertOk();
    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $contextId = $connection->browserbase_context_id;
    $activeRun = createWaitingBasketRun($workspace['team'], $workspace['user']);
    $activeRun->update([
        'retailer_connection_id' => $connection->id,
        'status' => BasketRunStatus::DiscoveringProducts,
    ]);

    $this->deleteJson("/retailer-connections/{$connectionId}/grant")
        ->assertOk()
        ->assertJsonPath('connection.standing_consent', false);

    expect($connection->grants()->sole()->refresh()->revoked_at)->not->toBeNull()
        ->and($activeRun->refresh()->status)->toBe(BasketRunStatus::WaitingForConnection)
        ->and($activeRun->failure_code)->toBe('standing_consent_revoked');

    $nextPlan = app(StartMealPlan::class)->handle(
        $workspace['team'],
        $workspace['user'],
        today()->addWeek(),
        today()->addWeek(),
    );
    $nextPlan->update(['planning_confirmed_at' => now()]);
    $nextPlan->milestones()->create([
        'team_id' => $workspace['team']->id,
        'user_id' => $workspace['user']->id,
        'kind' => MealPlanMilestoneKind::PlanningConfirmed,
        'plan_revision' => (int) ($nextPlan->revision ?? 0),
        'achieved_at' => now(),
    ]);

    expect(app(StartBasketRunForApprovedPlan::class)->handle($nextPlan, $workspace['user'])?->status)
        ->toBe(BasketRunStatus::WaitingForConnection);

    $this->deleteJson("/retailer-connections/{$connectionId}")
        ->assertOk()
        ->assertJsonPath('connection.status', RetailerConnectionStatus::Disconnected->value);

    $connection->refresh();
    expect($workspace['gateway']->deletedContexts)->toContain($contextId)
        ->and($connection->browserbase_context_id)->toBeNull()
        ->and($connection->context_lookup_hash)->toBeNull()
        ->and($connection->status)->toBe(RetailerConnectionStatus::Disconnected);
});

it('keeps consent revoked and the connection unusable when hosted Context cleanup fails', function () {
    Exceptions::fake();
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => true,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
    ])->assertOk();
    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $contextId = $connection->browserbase_context_id;
    $failingGateway = new class extends FakeRetailerAutomationGateway
    {
        public function deleteContext(string $contextId): void
        {
            throw new RuntimeException('Browserbase is unavailable.');
        }
    };

    app(DisconnectRetailerConnection::class)->handle(
        $connection,
        $workspace['user'],
        $failingGateway,
    );

    $connection->refresh();
    expect($connection->status)->toBe(RetailerConnectionStatus::Disconnected)
        ->and($connection->grants()->whereNull('revoked_at')->count())->toBe(0)
        ->and($connection->browserbase_context_id)->toBe($contextId)
        ->and($connection->failure_code)->toBe('context_cleanup_pending')
        ->and($connection->active_session_purpose)->toBe('disconnecting');
    Exceptions::assertReported(RuntimeException::class);
});

it('does not downgrade an existing connection when a new authentication session cannot open', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $this->postJson("/retailer-connections/{$connectionId}/verify", [
        'standing_consent' => true,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
    ])->assertOk();
    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $failingGateway = new class extends FakeRetailerAutomationGateway
    {
        public function startLiveSession(string $contextId, string $purpose): RetailerLiveSession
        {
            throw new RuntimeException('Live View failed to open.');
        }
    };

    expect(fn () => app(StartRetailerConnection::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $failingGateway,
    ))->toThrow(RuntimeException::class, 'Live View failed to open.');

    expect($connection->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($connection->active_session_id)->toBeNull()
        ->and($connection->failure_code)->toBeNull();
});

it('retains a newly provisioned Context for a safe retry when its first Live View cannot open', function () {
    $workspace = retailerConnectionWorkspace();
    $failingGateway = new class extends FakeRetailerAutomationGateway
    {
        public function startLiveSession(string $contextId, string $purpose): RetailerLiveSession
        {
            throw new RuntimeException('Live View failed to open.');
        }
    };

    expect(fn () => app(StartRetailerConnection::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $failingGateway,
    ))->toThrow(RuntimeException::class, 'Live View failed to open.');

    $connection = RetailerConnection::query()->sole();

    expect($failingGateway->deletedContexts)->toBe([])
        ->and($connection->status)->toBe(RetailerConnectionStatus::PendingAuthentication)
        ->and($connection->browserbase_context_id)->toStartWith('context_')
        ->and($connection->active_session_id)->toBeNull()
        ->and($connection->active_session_claim_token)->toBeNull();
});

it('does not clear or authorize a replacement session that appears during verification', function () {
    $workspace = retailerConnectionWorkspace();
    $connectionId = (int) $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->json('connection.id');
    $connection = RetailerConnection::query()->findOrFail($connectionId);
    $racingGateway = new class($connection) extends FakeRetailerAutomationGateway
    {
        public function __construct(private readonly RetailerConnection $connection)
        {
            parent::__construct();
        }

        public function execute(
            string $contextId,
            RetailerWorkerCommand $command,
            array $payload = [],
            ?string $sessionId = null,
        ): RetailerWorkerResult {
            if ($command === RetailerWorkerCommand::ProbeAuth) {
                $this->connection->update([
                    'active_session_id' => 'replacement-session',
                    'active_session_claim_token' => 'replacement-claim',
                    'active_session_purpose' => 'authentication',
                    'active_session_started_at' => now(),
                    'active_session_expires_at' => now()->addMinutes(5),
                ]);
            }

            return parent::execute($contextId, $command, $payload, $sessionId);
        }
    };

    expect(fn () => app(VerifyRetailerConnection::class)->handle(
        $connection,
        $workspace['user'],
        $racingGateway,
    ))->toThrow(ValidationException::class);

    $connection->refresh();
    expect($connection->active_session_id)->toBe('replacement-session')
        ->and($connection->active_session_claim_token)->toBe('replacement-claim')
        ->and($connection->status)->toBe(RetailerConnectionStatus::PendingAuthentication)
        ->and($connection->grants()->count())->toBe(0);
});
