<?php

namespace App\Actions\Retailers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Throwable;

class InspectRetailerOperationalReadiness
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CacheFactory $cache,
        private readonly RunRetailerQueueProbe $queueProbe,
        private readonly RuntimeRetailerMutationCircuitBreaker $circuitBreaker,
    ) {}

    /** @return array<string, mixed> */
    public function handle(bool $probeQueue = true): array
    {
        $database = $this->databaseCheck();
        $cache = $this->cacheCheck();
        $queue = $this->queueCheck($probeQueue, $cache['ok']);
        $worker = $this->workerCheck();
        $runtimeBreaker = $this->circuitBreaker->status();
        $features = [
            'experience' => (bool) config('retailer.features.experience', false),
            'discovery' => (bool) config('retailer.features.discovery', false),
            'mutation' => (bool) config('retailer.features.mutation', false),
            'deployment_circuit_breaker_open' => (bool) config(
                'retailer.features.mutation_circuit_breaker',
                true,
            ),
        ];
        $infrastructureReady = $database['ok'] && $cache['ok'] && $queue['ok'] && $worker['ok'];
        $flagsReady = $features['experience']
            && $features['discovery']
            && $features['mutation']
            && ! $features['deployment_circuit_breaker_open'];

        return [
            'checked_at' => now()->toIso8601String(),
            'infrastructure_ready' => $infrastructureReady,
            'mutation_ready' => $infrastructureReady && $flagsReady && $runtimeBreaker->state === 'closed',
            'checks' => [
                'database' => $database,
                'cache' => $cache,
                'queue' => $queue,
                'worker' => $worker,
            ],
            'features' => $features,
            'runtime_circuit_breaker' => $runtimeBreaker->toArray(),
        ];
    }

    /** @return array{ok: bool, code: string} */
    private function databaseCheck(): array
    {
        if (config('database.default') !== 'pgsql') {
            return ['ok' => false, 'code' => 'postgresql_required'];
        }

        try {
            $this->database->connection()->select('select 1');
        } catch (Throwable) {
            return ['ok' => false, 'code' => 'postgresql_unavailable'];
        }

        return ['ok' => true, 'code' => 'postgresql_ready'];
    }

    /** @return array{ok: bool, code: string} */
    private function cacheCheck(): array
    {
        if (config('cache.default') !== 'redis') {
            return ['ok' => false, 'code' => 'redis_cache_required'];
        }

        $key = 'chef:retailer:readiness-cache-probe';

        try {
            $store = $this->cache->store((string) config('retailer.readiness.cache_store', 'redis'));
            $store->put($key, 'ready', 10);
            $ready = $store->pull($key) === 'ready';
        } catch (Throwable) {
            $ready = false;
        }

        return [
            'ok' => $ready,
            'code' => $ready ? 'redis_cache_ready' : 'redis_cache_unavailable',
        ];
    }

    /** @return array{ok: bool, code: string, probed: bool} */
    private function queueCheck(bool $probeQueue, bool $cacheReady): array
    {
        if (config('queue.default') !== 'redis') {
            return ['ok' => false, 'code' => 'redis_queue_required', 'probed' => false];
        }

        if (! $probeQueue) {
            return ['ok' => true, 'code' => 'redis_queue_configured', 'probed' => false];
        }

        $ready = $cacheReady && $this->queueProbe->handle();

        return [
            'ok' => $ready,
            'code' => $ready ? 'redis_queue_ready' : 'redis_queue_probe_failed',
            'probed' => true,
        ];
    }

    /** @return array{ok: bool, code: string, protocol: string} */
    private function workerCheck(): array
    {
        $entrypoint = (string) config('retailer.worker.entrypoint');
        $protocolPath = dirname($entrypoint).DIRECTORY_SEPARATOR.'protocol.js';
        $protocol = (string) config('retailer.protocol');
        $ready = is_file($entrypoint)
            && is_readable($protocolPath)
            && str_contains((string) file_get_contents($protocolPath), $protocol);

        return [
            'ok' => $ready,
            'code' => $ready ? 'worker_ready' : 'worker_unavailable',
            'protocol' => $protocol,
        ];
    }
}
