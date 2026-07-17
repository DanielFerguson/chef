<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordReleaseQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly ?string $token = null)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $recordedAt = now()->toIso8601String();
        $expiresAt = now()->addMinutes(10);

        Cache::put('chef:release:queue-heartbeat', $recordedAt, $expiresAt);

        if ($this->token !== null) {
            Cache::put('chef:release:queue-probe:'.$this->token, $this->token, now()->addMinute());
        }
    }
}
