<?php

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStepStatus;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\BrowserConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ClaimNextAutomationStep
{
    public function handle(BrowserConnection $connection, AutomationRun $run): ?AutomationStep
    {
        return DB::transaction(function () use ($connection, $run): ?AutomationStep {
            $run = AutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->browser_connection_id !== $connection->id || $run->team_id !== $connection->team_id) {
                throw new AuthorizationException('This browser cannot claim work for that automation run.');
            }

            if ($run->status !== AutomationRunStatus::Executing || $run->expires_at->isPast()) {
                return null;
            }

            $step = AutomationStep::query()
                ->where('automation_run_id', $run->id)
                ->where('team_id', $run->team_id)
                ->where('status', AutomationStepStatus::Ready)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($step === null) {
                return null;
            }

            $step->update(['status' => AutomationStepStatus::Executing]);
            $connection->update(['last_seen_at' => now()]);

            return $step->refresh()->load('automationRun.retailer');
        });
    }
}
