<?php

use App\Actions\Baskets\StartBasketRunForApprovedPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Retailers\ReleaseRetailerLiveSession;
use App\Actions\Retailers\RevokeRetailerAutomationGrant;
use App\Actions\Retailers\StartRetailerConnection;
use App\Actions\Teams\CreateTeamForUser;
use App\Enums\BasketRunStatus;
use App\Enums\MealPlanMilestoneKind;
use App\Enums\RetailerAutomationScope;
use App\Enums\RetailerConnectionStatus;
use App\Enums\RetailerProvider;
use App\Enums\RetailerWorkerCommand;
use App\Enums\RetailerWorkerResultStatus;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\ProbeRetailerConnectionJob;
use App\Jobs\ReplaceBasketJob;
use App\Jobs\RestoreBasketJob;
use App\Models\BasketRun;
use App\Models\MealPlan;
use App\Models\RetailerConnection;
use App\Models\Team;
use App\Models\User;
use App\Retailer\Contracts\RetailerAutomationGateway;
use App\Retailer\Data\RetailerWorkerResult;
use App\Retailer\Testing\FakeRetailerAutomationGateway;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, team: Team, plan: MealPlan, connection: RetailerConnection, run: BasketRun, gateway: FakeRetailerAutomationGateway} */
function proactiveConnectionWorkspace(): array
{
    config()->set('retailer.features.experience', true);
    $user = User::factory()->create();
    $team = app(CreateTeamForUser::class)->handle($user, 'Proactive connection family');
    $plan = app(StartMealPlan::class)->handle($team, $user, today(), today());
    $plan->update(['planning_confirmed_at' => now()]);
    $plan->milestones()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'kind' => MealPlanMilestoneKind::PlanningConfirmed,
        'plan_revision' => (int) $plan->refresh()->revision,
        'achieved_at' => now(),
    ]);
    $connection = RetailerConnection::query()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'provider' => RetailerProvider::Coles,
        'status' => RetailerConnectionStatus::Connected,
        'browserbase_context_id' => 'context-proactive',
        'context_lookup_hash' => hash('sha256', 'context-proactive'),
        'authenticated_at' => now()->subWeek(),
        'last_verified_at' => now()->subDay(),
    ]);
    $connection->grants()->create([
        'team_id' => $team->id,
        'owner_user_id' => $user->id,
        'scope' => RetailerAutomationScope::ReplaceBasketAfterPlanApproval,
        'disclosure_version' => config('retailer.consent.disclosure_version'),
        'disclosure_hash' => hash('sha256', config('retailer.consent.disclosure')),
        'granted_at' => now()->subWeek(),
    ]);
    $gateway = new FakeRetailerAutomationGateway;
    $run = app(StartBasketRunForApprovedPlan::class)->handle($plan, $user);

    return compact('user', 'team', 'plan', 'connection', 'run', 'gateway');
}

it('queues a read-only authentication probe immediately after approval without waiting for recipes', function () {
    Queue::fake();

    $workspace = proactiveConnectionWorkspace();

    expect($workspace['run']->status)->toBe(BasketRunStatus::WaitingForRecipes);
    Queue::assertPushed(
        ProbeRetailerConnectionJob::class,
        fn (ProbeRetailerConnectionJob $job): bool => $job->retailerConnectionId === $workspace['connection']->id
            && $job->basketRunId === $workspace['run']->id,
    );
});

it('silently refreshes the verified timestamp after a successful proactive probe', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    $before = $workspace['connection']->last_verified_at;
    $this->travel(2)->minutes();

    app()->call([new ProbeRetailerConnectionJob(
        $workspace['connection']->id,
        $workspace['run']->id,
    ), 'handle'], ['gateway' => $workspace['gateway']]);

    expect($workspace['connection']->refresh()->last_verified_at->isAfter($before))->toBeTrue()
        ->and($workspace['connection']->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::WaitingForRecipes);
});

it('moves the connection and run to reauthentication when the saved Coles session expires', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    $workspace['gateway']->authenticated = false;

    app()->call([new ProbeRetailerConnectionJob(
        $workspace['connection']->id,
        $workspace['run']->id,
    ), 'handle'], ['gateway' => $workspace['gateway']]);

    expect($workspace['connection']->refresh()->status)->toBe(RetailerConnectionStatus::ReauthenticationRequired)
        ->and($workspace['connection']->failure_code)->toBe('authentication_required')
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::ReauthenticationRequired)
        ->and($workspace['run']->failure_code)->toBe('authentication_required');
});

it('leaves a transient probe failure for discovery to recheck later', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    $workspace['gateway']->queueResult(
        RetailerWorkerCommand::ProbeAuth,
        new RetailerWorkerResult(
            RetailerWorkerResultStatus::Retryable,
            'retailer_temporarily_unavailable',
            null,
            [],
        ),
    );

    app()->call([new ProbeRetailerConnectionJob(
        $workspace['connection']->id,
        $workspace['run']->id,
    ), 'handle'], ['gateway' => $workspace['gateway']]);

    expect($workspace['connection']->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::WaitingForRecipes);
});

it('does not probe after standing consent is revoked', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    app(RevokeRetailerAutomationGrant::class)->handle($workspace['connection'], $workspace['user']);

    app()->call([new ProbeRetailerConnectionJob(
        $workspace['connection']->id,
        $workspace['run']->id,
    ), 'handle'], ['gateway' => $workspace['gateway']]);

    expect($workspace['gateway']->commands)->toBeEmpty()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::WaitingForConnection);
});

it('defers a proactive probe while Live View owns the Context and resumes it after release', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    $workspace['connection']->update([
        'active_session_id' => 'session-review',
        'active_session_claim_token' => 'claim-review',
        'active_session_purpose' => 'review',
        'active_session_started_at' => now(),
        'active_session_expires_at' => now()->addMinutes(5),
    ]);

    app()->call([new ProbeRetailerConnectionJob(
        $workspace['connection']->id,
        $workspace['run']->id,
    ), 'handle'], ['gateway' => $workspace['gateway']]);

    expect($workspace['gateway']->commands)->toBeEmpty();

    app(ReleaseRetailerLiveSession::class)->handle(
        $workspace['connection']->refresh(),
        $workspace['user'],
        $workspace['gateway'],
    );

    Queue::assertPushed(
        ProbeRetailerConnectionJob::class,
        fn (ProbeRetailerConnectionJob $job): bool => $job->retailerConnectionId === $workspace['connection']->id
            && $job->basketRunId === $workspace['run']->id,
    );
});

it('shares one connection-level queue lock across probe discovery replacement and restoration', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    $probe = new ProbeRetailerConnectionJob($workspace['connection']->id, $workspace['run']->id);
    $jobs = [
        $probe,
        new DiscoverRetailerProductsJob($workspace['run']->id),
        new ReplaceBasketJob($workspace['run']->id),
        new RestoreBasketJob($workspace['run']->id),
    ];
    $lockKeys = collect($jobs)->map(function ($job): string {
        $middleware = collect($job->middleware())->first(
            fn (object $candidate): bool => $candidate instanceof WithoutOverlapping,
        );

        expect($middleware)->toBeInstanceOf(WithoutOverlapping::class);
        if (! $middleware instanceof WithoutOverlapping) {
            throw new RuntimeException('The retailer job is missing its connection-level lock.');
        }

        return $middleware->getLockKey($job);
    });

    expect($lockKeys->unique()->values()->all())->toBe([
        'laravel-queue-overlap:retailer-connection:'.$workspace['connection']->id,
    ]);
});

it('reauthenticates an existing standing grant without asking for consent again', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();
    app()->instance(RetailerAutomationGateway::class, $workspace['gateway']);
    $workspace['connection']->update([
        'status' => RetailerConnectionStatus::ReauthenticationRequired,
        'failure_code' => 'authentication_required',
    ]);

    $response = $this->actingAs($workspace['user'])
        ->postJson('/retailer-connections')
        ->assertCreated()
        ->assertJsonPath('connection.requires_standing_consent', false);

    $this->postJson('/retailer-connections/'.$workspace['connection']->id.'/verify')
        ->assertOk()
        ->assertJsonPath('connection.status', RetailerConnectionStatus::Connected->value)
        ->assertJsonPath('connection.standing_consent', true);

    expect($workspace['connection']->grants()->whereNull('revoked_at')->count())->toBe(1)
        ->and($response->json('connection.id'))->toBe($workspace['connection']->id);
});

it('does not open authentication Live View while retailer automation is active', function () {
    Queue::fake();
    $workspace = proactiveConnectionWorkspace();

    expect(fn () => app(StartRetailerConnection::class)->handle(
        $workspace['team'],
        $workspace['user'],
        $workspace['gateway'],
    ))->toThrow(ValidationException::class);

    expect($workspace['connection']->refresh()->status)->toBe(RetailerConnectionStatus::Connected)
        ->and($workspace['connection']->active_session_id)->toBeNull()
        ->and($workspace['run']->refresh()->status)->toBe(BasketRunStatus::WaitingForRecipes);
});
