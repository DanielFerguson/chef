<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class AdvanceRetailerOrderRunJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $retailerOrderRunId) {}

    public function uniqueId(): string
    {
        return 'retailer-order-run:'.$this->retailerOrderRunId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(5)
                ->expireAfter((int) config('automation.lease_seconds', 900)),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 45, 120];
    }

    public function handle(): void
    {
        // Task 7 implements AdvanceRetailerOrderRun
    }
}
