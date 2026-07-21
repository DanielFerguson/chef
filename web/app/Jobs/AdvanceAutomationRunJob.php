<?php

namespace App\Jobs;

use App\Actions\Automation\CloseBrowserSession;
use App\Automation\Contracts\ComputerUseEngine;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AdvanceAutomationRunJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $automationRunId) {}

    public function uniqueId(): string
    {
        return 'automation-run:'.$this->automationRunId;
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

    public function handle(ComputerUseEngine $engine): void
    {
        Cache::lock('chef-automation-run:'.$this->automationRunId, (int) config('automation.lease_seconds', 900))
            ->block(5, fn () => $this->advance($engine));
    }

    private function advance(ComputerUseEngine $engine): void
    {
        $iterations = config('queue.default') === 'sync' ? 50 : 1;
        $shouldContinue = false;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $run = AutomationRun::query()->findOrFail($this->automationRunId);
            $result = $engine->advance($run);
            $shouldContinue = $result->shouldContinue;

            if (! $shouldContinue) {
                return;
            }
        }

        if (config('queue.default') !== 'sync') {
            self::dispatch($this->automationRunId)
                ->onQueue((string) config('automation.queue', 'automation'))
                ->delay(now()->addSecond());
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = AutomationRun::query()->find($this->automationRunId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $remoteMutationMayHaveStarted = $run->items()->where('attempts', '>', 0)->exists()
            || $run->steps()->whereIn('action_type', [
                'clear_existing_cart',
                'prepare_and_verify_item',
                'click',
                'double_click',
                'drag',
                'keypress',
                'scroll',
                'type',
            ])->exists();
        $run->update([
            'status' => AutomationRunStatus::Failed,
            'failure_message' => $remoteMutationMayHaveStarted
                ? 'Cart preparation stopped after repeated safe retries. The remote cart must be reconciled before another run.'
                : 'Chef could not reach the browser provider after several attempts. No Woolworths cart changes were made; check the automation worker connection and start a new run.',
            'finished_at' => now(),
        ]);

        foreach ($run->browserSessions()->whereNull('ended_at')->get() as $session) {
            try {
                app(CloseBrowserSession::class)->handle($session);
            } catch (Throwable) {
                // The provider session remains bounded by its expiry and the
                // lease remains bounded if release cannot be confirmed.
            }
        }
    }
}
