<?php

namespace App\Actions\Automation;

use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationApproval;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpireAutomationArtifacts
{
    public function __construct(private readonly InvalidateAutomationRunWork $invalidateWork) {}

    /** @return array{runs: int, approvals: int, screenshots: int, connections: int, stalled_steps: int} */
    public function handle(): array
    {
        $stalledSteps = 0;
        AutomationStep::query()
            ->where('status', AutomationStepStatus::Executing)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->each(function (AutomationStep $step) use (&$stalledSteps): void {
                $run = DB::transaction(function () use ($step): ?AutomationRun {
                    $step = AutomationStep::query()->whereKey($step->id)->lockForUpdate()->first();

                    if ($step === null || $step->status !== AutomationStepStatus::Executing) {
                        return null;
                    }

                    $run = $step->automationRun()->lockForUpdate()->firstOrFail();
                    $step->update([
                        'status' => AutomationStepStatus::Failed,
                        'error_message' => 'The browser extension did not return this action batch within five minutes.',
                    ]);

                    if ($run->status === AutomationRunStatus::Executing) {
                        $run->update([
                            'status' => AutomationRunStatus::Takeover,
                            'error_code' => 'extension_result_timeout',
                            'error_message' => 'Chef stopped because the browser extension did not return an action result.',
                            'pause_reason' => 'Check the selected retailer tab before deciding whether to resume.',
                            'takeover_at' => now(),
                        ]);
                    }

                    return $run->refresh();
                });

                if ($run !== null) {
                    $stalledSteps++;
                    AutomationRunUpdated::dispatch($run);
                }
            });

        $runs = 0;
        AutomationRun::query()
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
            ->each(function (AutomationRun $candidate) use (&$runs): void {
                $run = DB::transaction(function () use ($candidate): ?AutomationRun {
                    $run = AutomationRun::query()->whereKey($candidate->id)->lockForUpdate()->first();

                    if ($run === null || $run->status->terminal() || $run->expires_at->isFuture()) {
                        return null;
                    }

                    $this->invalidateWork->handle($run, 'This automation run expired before the browser work completed.');
                    $run->update([
                        'status' => AutomationRunStatus::Expired,
                        'pause_reason' => 'This cart-preparation run expired. Start a new run from the current shopping list.',
                        'finished_at' => now(),
                    ]);

                    return $run->refresh();
                });

                if ($run !== null) {
                    $runs++;
                    AutomationRunUpdated::dispatch($run);
                }
            });

        $approvals = 0;
        $runIdsWithExpiredApprovals = AutomationApproval::query()
            ->where('expires_at', '<=', now())
            ->where('status', AutomationApprovalStatus::Pending)
            ->distinct()
            ->pluck('automation_run_id');

        foreach ($runIdsWithExpiredApprovals as $runId) {
            $result = DB::transaction(function () use ($runId): ?array {
                $run = AutomationRun::query()->whereKey($runId)->lockForUpdate()->first();

                if ($run === null) {
                    return null;
                }

                $expiredCount = $run->approvals()
                    ->where('status', AutomationApprovalStatus::Pending)
                    ->where('expires_at', '<=', now())
                    ->count();

                if ($expiredCount === 0) {
                    return null;
                }

                $this->invalidateWork->handle($run, 'An approval expired before this browser action was authorised.');

                if ($run->status === AutomationRunStatus::AwaitingApproval) {
                    $run->update([
                        'status' => AutomationRunStatus::Takeover,
                        'pause_reason' => 'An approval expired. Review the retailer tab before continuing.',
                        'takeover_at' => now(),
                    ]);
                }

                return ['run' => $run->refresh(), 'count' => $expiredCount];
            });

            if ($result !== null) {
                $approvals += $result['count'];
                AutomationRunUpdated::dispatch($result['run']);
            }
        }
        $screenshots = 0;

        AutomationStep::query()
            ->whereNotNull('screenshot_path')
            ->where('screenshot_expires_at', '<=', now())
            ->each(function (AutomationStep $step) use (&$screenshots): void {
                Storage::disk((string) config('chef.storage.automation_screenshots_disk'))->delete((string) $step->screenshot_path);
                $step->update(['screenshot_path' => null, 'screenshot_expires_at' => null]);
                $screenshots++;
            });

        $connections = 0;
        BrowserConnection::query()
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['pending', 'active'])
            ->each(function (BrowserConnection $candidate) use (&$connections): void {
                $runs = collect();
                $connection = DB::transaction(function () use ($candidate, $runs): ?BrowserConnection {
                    $connection = BrowserConnection::query()->whereKey($candidate->id)->lockForUpdate()->first();

                    if ($connection === null || $connection->expires_at->isFuture() || ! in_array($connection->status->value, ['pending', 'active'], true)) {
                        return null;
                    }

                    $connection->automationRuns()
                        ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
                        ->lockForUpdate()
                        ->get()
                        ->each(function (AutomationRun $run) use ($runs): void {
                            $this->invalidateWork->handle($run, 'The paired browser connection expired before this work completed.');
                            $run->update([
                                'status' => AutomationRunStatus::Cancelled,
                                'error_code' => 'browser_connection_expired',
                                'error_message' => 'The paired browser connection expired.',
                                'finished_at' => now(),
                            ]);
                            $runs->push($run->refresh());
                        });
                    $connection->update([
                        'status' => 'expired',
                        'pairing_code_hash' => null,
                        'token_hash' => null,
                    ]);

                    return $connection->refresh();
                });

                if ($connection !== null) {
                    $connections++;
                    $runs->each(fn (AutomationRun $run) => AutomationRunUpdated::dispatch($run));
                }
            });

        return [
            'runs' => $runs,
            'approvals' => $approvals,
            'screenshots' => $screenshots,
            'connections' => $connections,
            'stalled_steps' => $stalledSteps,
        ];
    }
}
