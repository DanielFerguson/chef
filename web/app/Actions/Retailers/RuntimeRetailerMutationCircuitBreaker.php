<?php

namespace App\Actions\Retailers;

use App\Retailer\Data\RetailerCircuitBreakerStatus;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class RuntimeRetailerMutationCircuitBreaker
{
    public function __construct(private readonly CacheFactory $cache) {}

    public function status(): RetailerCircuitBreakerStatus
    {
        try {
            $value = $this->cache->store($this->store())->get($this->key());
        } catch (Throwable) {
            return new RetailerCircuitBreakerStatus('open', 'store_unavailable', null, false);
        }

        if (! is_array($value) || ! in_array($value['state'] ?? null, ['open', 'closed'], true)) {
            return new RetailerCircuitBreakerStatus('open', 'state_missing', null, true);
        }

        try {
            $changedAt = is_string($value['changed_at'] ?? null)
                ? Carbon::parse($value['changed_at'])
                : null;
        } catch (Throwable) {
            return new RetailerCircuitBreakerStatus('open', 'state_invalid', null, true);
        }

        return new RetailerCircuitBreakerStatus(
            state: $value['state'],
            reason: is_string($value['reason'] ?? null) ? $value['reason'] : 'operator_action',
            changedAt: $changedAt,
            available: true,
        );
    }

    public function isOpen(): bool
    {
        return $this->status()->state !== 'closed';
    }

    public function open(string $reason): RetailerCircuitBreakerStatus
    {
        return $this->persist('open', $reason);
    }

    public function close(string $reason): RetailerCircuitBreakerStatus
    {
        return $this->persist('closed', $reason);
    }

    private function persist(string $state, string $reason): RetailerCircuitBreakerStatus
    {
        $changedAt = now();
        $safeReason = Str::of($reason)->squish()->limit(160, '')->toString();

        try {
            $this->cache->store($this->store())->forever($this->key(), [
                'state' => $state,
                'reason' => $safeReason,
                'changed_at' => $changedAt->toIso8601String(),
            ]);
        } catch (Throwable) {
            return new RetailerCircuitBreakerStatus('open', 'store_unavailable', null, false);
        }

        return new RetailerCircuitBreakerStatus($state, $safeReason, $changedAt, true);
    }

    private function store(): string
    {
        return (string) config('retailer.runtime_circuit_breaker.store', 'redis');
    }

    private function key(): string
    {
        return (string) config(
            'retailer.runtime_circuit_breaker.key',
            'chef:retailer:mutation-circuit-breaker',
        );
    }
}
