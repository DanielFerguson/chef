<?php

namespace App\Actions\Automation;

use App\Automation\RetailerOriginPolicy;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Jobs\AdvanceAutomationRunJob;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachBrowserTab
{
    public function __construct(
        private readonly RetailerOriginPolicy $origins,
        private readonly StoreAutomationScreenshot $screenshots,
    ) {}

    public function handle(AutomationRun $run, BrowserConnection $connection, string $tabId, string $url, string $screenshot): AutomationRun
    {
        if ($run->browser_connection_id !== $connection->id || $run->team_id !== $connection->team_id) {
            throw new AuthorizationException('This browser connection cannot control the automation run.');
        }

        $this->assertRunnable($run);
        $this->origins->assertAllowed($run->retailer, $url);
        $this->assertBeforeCheckout($url);
        $stored = null;

        try {
            $run = DB::transaction(function () use ($run, $connection, $tabId, $url, $screenshot, &$stored): AutomationRun {
                $run = AutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $this->assertRunnable($run);
                $this->origins->assertAllowed($run->retailer, $url);
                $this->assertBeforeCheckout($url);

                if ($run->current_tab_id !== null && $run->current_tab_id !== $tabId) {
                    throw ValidationException::withMessages(['tab_id' => 'This run is already scoped to another browser tab.']);
                }

                $stored = $this->screenshots->handle($run, $screenshot);
                $latestSequence = $run->steps()->max('sequence');
                $sequence = $latestSequence === null ? 0 : ((int) $latestSequence) + 1;

                $run->steps()->create([
                    'team_id' => $run->team_id,
                    'sequence' => $sequence,
                    'status' => AutomationStepStatus::Observed,
                    'current_url' => $url,
                    'screenshot_path' => $stored['path'],
                    'screenshot_expires_at' => $stored['expires_at'],
                    'executed_at' => now(),
                ]);
                $run->update([
                    'status' => AutomationRunStatus::Queued,
                    'current_tab_id' => $tabId,
                    'current_url' => $url,
                    'started_at' => $run->started_at ?? now(),
                ]);
                $connection->update(['last_seen_at' => now()]);

                return $run->refresh();
            });
        } catch (\Throwable $exception) {
            if (is_array($stored)) {
                Storage::disk((string) config('chef.storage.automation_screenshots_disk'))->delete((string) $stored['path']);
            }

            throw $exception;
        }

        AutomationRunUpdated::dispatch($run);
        AdvanceAutomationRunJob::dispatch($run);

        return $run;
    }

    private function assertRunnable(AutomationRun $run): void
    {
        if ($run->expires_at->isPast()) {
            $run->update(['status' => AutomationRunStatus::Expired, 'finished_at' => now()]);
            throw ValidationException::withMessages(['automation_run' => 'This cart-preparation run has expired.']);
        }

        if ($run->status !== AutomationRunStatus::AwaitingBrowser) {
            throw ValidationException::withMessages(['automation_run' => 'This run is not waiting for a browser tab.']);
        }
    }

    private function assertBeforeCheckout(string $url): void
    {
        if ($this->origins->requiresTakeover($url)) {
            throw ValidationException::withMessages(['current_url' => 'Select the retailer cart or product pages, not checkout, account, address, delivery, or payment pages.']);
        }
    }
}
