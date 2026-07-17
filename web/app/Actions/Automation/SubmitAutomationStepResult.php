<?php

namespace App\Actions\Automation;

use App\Automation\RetailerOriginPolicy;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitAutomationStepResult
{
    public function __construct(
        private readonly RetailerOriginPolicy $origins,
        private readonly StoreAutomationScreenshot $screenshots,
    ) {}

    /** @param array<string, mixed> $result */
    public function handle(AutomationStep $step, BrowserConnection $connection, string $url, string $screenshot, array $result): AutomationStep
    {
        $step->loadMissing('automationRun.retailer');
        $run = $step->automationRun;

        if ($run->browser_connection_id !== $connection->id || $run->team_id !== $connection->team_id) {
            throw new AuthorizationException('This browser cannot submit the automation step.');
        }

        if ($step->status === AutomationStepStatus::Completed) {
            return $step;
        }

        if ($step->status !== AutomationStepStatus::Executing || $run->status !== AutomationRunStatus::Executing) {
            throw ValidationException::withMessages(['step' => 'This automation step is not awaiting a browser result.']);
        }

        $this->origins->assertAllowed($run->retailer, $url);
        $stored = $this->screenshots->handle($run, $screenshot);

        try {
            $step = DB::transaction(function () use ($step, $connection, $url, $stored, $result): AutomationStep {
                $step = AutomationStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
                $run = $step->automationRun()->lockForUpdate()->firstOrFail();

                if ($step->status === AutomationStepStatus::Completed) {
                    return $step;
                }

                if ($step->status !== AutomationStepStatus::Executing || $run->status !== AutomationRunStatus::Executing) {
                    throw ValidationException::withMessages(['step' => 'This automation step is no longer awaiting a browser result.']);
                }

                $failed = ($result['ok'] ?? true) === false;
                $step->update([
                    'status' => $failed ? AutomationStepStatus::Failed : AutomationStepStatus::Completed,
                    'result' => $result,
                    'current_url' => $url,
                    'screenshot_path' => $stored['path'],
                    'screenshot_expires_at' => $stored['expires_at'],
                    'executed_at' => now(),
                    'error_message' => $failed ? (string) ($result['error'] ?? 'The extension could not execute this browser action.') : null,
                ]);
                $connection->update(['last_seen_at' => now()]);

                if ($failed) {
                    $run->update([
                        'status' => AutomationRunStatus::Failed,
                        'current_url' => $url,
                        'error_code' => 'extension_action_failed',
                        'error_message' => 'The browser extension could not safely execute the requested action.',
                        'finished_at' => now(),
                    ]);
                } elseif ($this->origins->requiresTakeover($url)) {
                    $run->update([
                        'status' => AutomationRunStatus::Takeover,
                        'current_url' => $url,
                        'pause_reason' => 'Chef stopped before checkout, authentication, address, delivery, or payment controls.',
                        'takeover_at' => now(),
                    ]);
                } else {
                    $run->update(['status' => AutomationRunStatus::Queued, 'current_url' => $url]);
                }

                return $step->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk((string) config('chef.storage.automation_screenshots_disk'))->delete((string) $stored['path']);

            throw $exception;
        }

        if ($step->screenshot_path !== $stored['path']) {
            Storage::disk((string) config('chef.storage.automation_screenshots_disk'))->delete((string) $stored['path']);
        }

        $run = $step->automationRun->refresh();
        AutomationRunUpdated::dispatch($run);

        if ($run->status === AutomationRunStatus::Queued) {
            AdvanceAutomationRunJob::dispatch($run);
        }

        return $step;
    }
}
