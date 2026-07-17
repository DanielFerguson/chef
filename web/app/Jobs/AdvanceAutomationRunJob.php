<?php

namespace App\Jobs;

use App\Actions\Automation\AdvanceAutomationRun;
use App\Enums\AutomationRunStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AdvanceAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [2, 10, 30];

    public function __construct(public readonly AutomationRun $run)
    {
        $this->onQueue('automation');
    }

    public function handle(AdvanceAutomationRun $advance): void
    {
        $advance->handle($this->run->refresh());
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->run->refresh();

        if ($run->status === AutomationRunStatus::Queued && $run->error_code === 'computer_use_retrying') {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'error_code' => 'computer_use_failed',
                'error_message' => 'Chef could not safely continue cart preparation after retrying.',
                'finished_at' => now(),
            ]);
            AutomationRunUpdated::dispatch($run->refresh());
        }
    }
}
