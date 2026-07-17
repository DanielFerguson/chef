<?php

namespace App\Console\Commands;

use App\Jobs\RecordReleaseQueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecordReleaseSchedulerHeartbeat extends Command
{
    protected $signature = 'chef:release:heartbeat';

    protected $description = 'Record scheduler and queue-worker heartbeats for release health checks';

    public function handle(): int
    {
        Cache::put(
            'chef:release:scheduler-heartbeat',
            now()->toIso8601String(),
            now()->addMinutes(10),
        );

        RecordReleaseQueueProbe::dispatch();

        $this->info('Chef release heartbeats dispatched.');

        return self::SUCCESS;
    }
}
