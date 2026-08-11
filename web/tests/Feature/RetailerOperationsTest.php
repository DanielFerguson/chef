<?php

use App\Actions\Retailers\BuildRetailerOperationalMetrics;
use App\Actions\Retailers\EnsureRetailerMutationIsEnabled;
use App\Actions\Retailers\InspectRetailerOperationalReadiness;
use App\Actions\Retailers\RuntimeRetailerMutationCircuitBreaker;
use App\Enums\BasketRunStatus;
use App\Jobs\RecordRetailerQueueProbeJob;
use App\Models\BasketRun;
use App\Models\BasketRunStatusTransition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config()->set('retailer.runtime_circuit_breaker.store', 'array');
    config()->set('retailer.runtime_circuit_breaker.key', 'chef:testing:retailer-mutation-circuit-breaker');
    Cache::store('array')->forget(config('retailer.runtime_circuit_breaker.key'));
});

it('fails closed when the runtime circuit breaker state is absent or unreadable', function () {
    $breaker = app(RuntimeRetailerMutationCircuitBreaker::class);

    expect($breaker->status())
        ->state->toBe('open')
        ->available->toBeTrue()
        ->reason->toBe('state_missing');

    config()->set('retailer.runtime_circuit_breaker.store', 'missing-store');

    expect($breaker->status())
        ->state->toBe('open')
        ->available->toBeFalse()
        ->reason->toBe('store_unavailable');
});

it('fails closed when the runtime circuit breaker state is malformed', function () {
    Cache::store('array')->forever(config('retailer.runtime_circuit_breaker.key'), [
        'state' => 'closed',
        'reason' => 'test',
        'changed_at' => 'not-a-date',
    ]);

    expect(app(RuntimeRetailerMutationCircuitBreaker::class)->status())
        ->state->toBe('open')
        ->available->toBeTrue()
        ->reason->toBe('state_invalid');
});

it('persists open and closed runtime circuit breaker decisions without an expiry', function () {
    $breaker = app(RuntimeRetailerMutationCircuitBreaker::class);

    $closed = $breaker->close('internal mutation pilot');
    expect($closed)
        ->state->toBe('closed')
        ->reason->toBe('internal mutation pilot')
        ->available->toBeTrue();

    $open = $breaker->open('operator kill switch');
    expect($open)
        ->state->toBe('open')
        ->reason->toBe('operator kill switch')
        ->available->toBeTrue();
});

it('requires both the deployment and runtime circuit breakers to permit mutation', function () {
    config()->set('retailer.features.mutation', true);
    config()->set('retailer.features.mutation_circuit_breaker', false);
    app(RuntimeRetailerMutationCircuitBreaker::class)->close('test');

    expect(fn () => app(EnsureRetailerMutationIsEnabled::class)->handle())
        ->not->toThrow(RuntimeException::class);

    app(RuntimeRetailerMutationCircuitBreaker::class)->open('incident');

    expect(fn () => app(EnsureRetailerMutationIsEnabled::class)->handle())
        ->toThrow(RuntimeException::class, 'Retailer basket mutation is disabled by the runtime circuit breaker.');

    app(RuntimeRetailerMutationCircuitBreaker::class)->close('incident resolved');
    config()->set('retailer.features.mutation_circuit_breaker', true);

    expect(fn () => app(EnsureRetailerMutationIsEnabled::class)->handle())
        ->toThrow(RuntimeException::class, 'Retailer basket mutation is disabled.');
});

it('aggregates safe retailer outcome duration restoration and fallback metrics', function () {
    $readyRun = BasketRun::factory()->create([
        'status' => BasketRunStatus::Ready,
        'stagehand_fallback_count' => 2,
    ]);
    $failedRun = BasketRun::factory()->create([
        'status' => BasketRunStatus::Failed,
        'stagehand_fallback_count' => 1,
    ]);

    BasketRunStatusTransition::factory()->for($readyRun)->create([
        'team_id' => $readyRun->team_id,
        'from_status' => BasketRunStatus::ReplacingBasket->value,
        'to_status' => BasketRunStatus::Ready->value,
        'duration_ms' => 900,
        'transitioned_at' => now()->subMinutes(15),
    ]);
    BasketRunStatusTransition::factory()->for($failedRun)->create([
        'team_id' => $failedRun->team_id,
        'from_status' => BasketRunStatus::Restoring->value,
        'to_status' => BasketRunStatus::NeedsAttention->value,
        'reason_code' => 'restore_incomplete',
        'duration_ms' => 2100,
        'transitioned_at' => now()->subMinutes(5),
    ]);
    BasketRunStatusTransition::factory()->for($failedRun)->create([
        'team_id' => $failedRun->team_id,
        'from_status' => BasketRunStatus::DiscoveringProducts->value,
        'to_status' => BasketRunStatus::Failed->value,
        'reason_code' => 'safe_failure_code',
        'duration_ms' => 400,
        'transitioned_at' => now()->subDays(2),
    ]);

    $metrics = app(BuildRetailerOperationalMetrics::class)->handle(now()->subDay());

    expect($metrics['outcomes'])->toBe([
        'ready' => 1,
        'failed' => 0,
        'uncertain' => 0,
        'needs_attention' => 1,
        'restored' => 0,
    ])->and($metrics['restoration'])->toBe([
        'completed' => 0,
        'incomplete' => 1,
    ])->and($metrics['transitions'])->toMatchArray([
        'count' => 2,
        'average_duration_ms' => 1500,
        'p50_duration_ms' => 900,
        'p95_duration_ms' => 2100,
        'maximum_duration_ms' => 2100,
    ])->and($metrics['stagehand_fallback_count'])->toBe(3)
        ->and(json_encode($metrics, JSON_THROW_ON_ERROR))->not->toContain('safe_failure_code');
});

it('reports local infrastructure as fail-closed JSON without probing the network', function () {
    $exitCode = Artisan::call('retailer:readiness', [
        '--json' => true,
        '--no-queue-probe' => true,
    ]);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($result['infrastructure_ready'])->toBeFalse()
        ->and($result['mutation_ready'])->toBeFalse()
        ->and($result['checks']['database']['code'])->toBe('postgresql_required')
        ->and($result['checks']['cache']['code'])->toBe('redis_cache_required')
        ->and($result['checks']['queue']['code'])->toBe('redis_queue_required');
});

it('requires a reason and ready deployment before operating the circuit breaker', function () {
    expect(Artisan::call('retailer:circuit-breaker', ['action' => 'open']))->toBe(2);

    $readiness = new class extends InspectRetailerOperationalReadiness
    {
        public function __construct() {}

        public function handle(bool $probeQueue = true): array
        {
            expect($probeQueue)->toBeTrue();

            return [
                'infrastructure_ready' => true,
                'features' => [
                    'experience' => true,
                    'discovery' => true,
                    'mutation' => true,
                    'deployment_circuit_breaker_open' => false,
                ],
            ];
        }
    };
    app()->instance(InspectRetailerOperationalReadiness::class, $readiness);

    expect(Artisan::call('retailer:circuit-breaker', [
        'action' => 'close',
        '--reason' => 'internal mutation pilot',
        '--json' => true,
    ]))->toBe(0);

    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray([
        'state' => 'closed',
        'reason' => 'internal mutation pilot',
        'available' => true,
    ]);
});

it('uses a harmless cache marker for the retailer queue probe job', function () {
    config()->set('retailer.readiness.cache_store', 'array');
    $job = new RecordRetailerQueueProbeJob('test-probe');

    $job->handle();

    expect(Cache::store('array')->pull($job->cacheKey()))->toBeTrue();
});

it('renders aggregate metrics as JSON for operators', function () {
    expect(Artisan::call('retailer:metrics', [
        '--since' => '24h',
        '--json' => true,
    ]))->toBe(0);

    $metrics = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($metrics)->toHaveKeys([
        'window_started_at',
        'generated_at',
        'outcomes',
        'restoration',
        'transitions',
        'stagehand_fallback_count',
    ]);
});
