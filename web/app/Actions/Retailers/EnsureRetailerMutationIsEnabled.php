<?php

namespace App\Actions\Retailers;

use RuntimeException;

class EnsureRetailerMutationIsEnabled
{
    public function __construct(
        private readonly RuntimeRetailerMutationCircuitBreaker $runtimeCircuitBreaker,
    ) {}

    public function handle(bool $allowRuntimeBreakerOpenForActiveRecovery = false): void
    {
        if (! config('retailer.features.mutation', false)
            || config('retailer.features.mutation_circuit_breaker', true)) {
            throw new RuntimeException('Retailer basket mutation is disabled.');
        }

        if (! app()->environment('testing')
            && (config('database.default') !== 'pgsql'
                || config('queue.default') !== 'redis'
                || config('cache.default') !== 'redis')) {
            throw new RuntimeException('Live retailer automation requires PostgreSQL and Redis.');
        }

        if (! $allowRuntimeBreakerOpenForActiveRecovery && $this->runtimeCircuitBreaker->isOpen()) {
            throw new RuntimeException('Retailer basket mutation is disabled by the runtime circuit breaker.');
        }
    }
}
