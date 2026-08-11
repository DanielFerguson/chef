<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RecordRetailerQueueProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $probeId)
    {
        $this->onConnection('redis');
        $this->onQueue('retailer');
    }

    public function handle(): void
    {
        Cache::store((string) config('retailer.readiness.cache_store', 'redis'))
            ->put($this->cacheKey(), true, now()->addMinute());
    }

    public function cacheKey(): string
    {
        return 'chef:retailer:queue-probe:'.$this->probeId;
    }

    public function failed(?Throwable $exception): void
    {
        Cache::store((string) config('retailer.readiness.cache_store', 'redis'))
            ->forget($this->cacheKey());
    }
}
