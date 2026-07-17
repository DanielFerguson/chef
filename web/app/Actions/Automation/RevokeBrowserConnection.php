<?php

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\BrowserConnectionStatus;
use App\Models\BrowserConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RevokeBrowserConnection
{
    public function handle(BrowserConnection $connection, User $user): BrowserConnection
    {
        if (! $user->can('update', $connection)) {
            throw new AuthorizationException('You cannot revoke this browser connection.');
        }

        return DB::transaction(function () use ($connection): BrowserConnection {
            $connection->update([
                'status' => BrowserConnectionStatus::Revoked,
                'token_hash' => null,
                'revoked_at' => now(),
            ]);
            $connection->automationRuns()
                ->whereNotIn('status', [AutomationRunStatus::Completed, AutomationRunStatus::Failed, AutomationRunStatus::Cancelled, AutomationRunStatus::Expired])
                ->update([
                    'status' => AutomationRunStatus::Cancelled,
                    'error_code' => 'browser_revoked',
                    'error_message' => 'The paired browser was revoked.',
                    'finished_at' => now(),
                ]);

            return $connection->refresh();
        });
    }
}
