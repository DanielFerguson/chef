<?php

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\BrowserConnectionStatus;
use App\Events\AutomationRunUpdated;
use App\Models\AutomationRun;
use App\Models\BrowserConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RevokeBrowserConnection
{
    public function __construct(private readonly InvalidateAutomationRunWork $invalidateWork) {}

    public function handle(BrowserConnection $connection, User $user): BrowserConnection
    {
        if (! $user->can('update', $connection)) {
            throw new AuthorizationException('You cannot revoke this browser connection.');
        }

        $runs = collect();
        $connection = DB::transaction(function () use ($connection, $runs): BrowserConnection {
            $connection = BrowserConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $connection->update([
                'status' => BrowserConnectionStatus::Revoked,
                'token_hash' => null,
                'revoked_at' => now(),
            ]);
            $connection->automationRuns()
                ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
                ->lockForUpdate()
                ->get()
                ->each(function (AutomationRun $run) use ($runs): void {
                    $this->invalidateWork->handle($run, 'The paired browser was revoked before this work completed.');
                    $run->update([
                        'status' => AutomationRunStatus::Cancelled,
                        'error_code' => 'browser_revoked',
                        'error_message' => 'The paired browser was revoked.',
                        'finished_at' => now(),
                    ]);
                    $runs->push($run->refresh());
                });

            return $connection->refresh();
        });

        $runs->each(fn (AutomationRun $run) => AutomationRunUpdated::dispatch($run));

        return $connection;
    }
}
