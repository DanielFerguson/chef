<?php

namespace App\Jobs;

use App\Actions\Retailer\AdvanceRetailerOrderRun;
use App\Enums\RetailerOrderRunStatus;
use App\Models\RetailerOrderRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    public function handle(AdvanceRetailerOrderRun $advance): void
    {
        Cache::lock('chef-retailer-order-run:'.$this->retailerOrderRunId, (int) config('automation.lease_seconds', 900))
            ->block(5, fn () => $this->advance($advance));
    }

    private function advance(AdvanceRetailerOrderRun $advance): void
    {
        $iterations = config('queue.default') === 'sync' ? 50 : 1;
        $shouldContinue = false;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $run = RetailerOrderRun::query()->find($this->retailerOrderRunId);

            if ($run === null) {
                return;
            }

            $result = $advance->handle($run);
            $shouldContinue = $result->shouldContinue;

            if (! $shouldContinue) {
                return;
            }
        }

        if (config('queue.default') !== 'sync') {
            self::dispatch($this->retailerOrderRunId)
                ->onQueue((string) config('automation.queue', 'automation'))
                ->delay(now()->addSecond());
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = RetailerOrderRun::query()->find($this->retailerOrderRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $run->update([
            'status' => RetailerOrderRunStatus::Failed,
            'failure_message' => 'Chef could not advance this Woolworths order run after several attempts.',
        ]);
    }
}
