<?php

namespace App\Actions\Retailers;

use App\Jobs\RecordRetailerQueueProbeJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Str;
use Throwable;

class RunRetailerQueueProbe
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CacheFactory $cache,
    ) {}

    public function handle(): bool
    {
        $probe = new RecordRetailerQueueProbeJob((string) Str::uuid());
        $store = $this->cache->store((string) config('retailer.readiness.cache_store', 'redis'));

        try {
            $store->forget($probe->cacheKey());
            $this->dispatcher->dispatch($probe);
            $deadline = microtime(true) + (float) config('retailer.readiness.queue_probe_timeout_seconds', 5);

            do {
                if ($store->pull($probe->cacheKey()) === true) {
                    return true;
                }

                usleep(100_000);
            } while (microtime(true) < $deadline);
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
