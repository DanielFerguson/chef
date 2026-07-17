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
    public function handle(BrowserConnection $connection): ?AutomationStep
    {
        return DB::transaction(function () use ($connection): ?AutomationStep {
            $step = AutomationStep::query()
                ->where('team_id', $connection->team_id)
                ->where('status', AutomationStepStatus::Ready)
                ->whereHas('automationRun', fn ($query) => $query
                    ->where('browser_connection_id', $connection->id)
                    ->where('status', AutomationRunStatus::Executing)
                    ->where('expires_at', '>', now()))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($step === null) {
                return null;
            }

            $run = AutomationRun::query()->whereKey($step->automation_run_id)->lockForUpdate()->firstOrFail();

            if ($run->browser_connection_id !== $connection->id) {
                throw new AuthorizationException('This browser cannot claim the automation step.');
            }

            $step->update(['status' => AutomationStepStatus::Executing]);
            $connection->update(['last_seen_at' => now()]);

            return $step->refresh()->load('automationRun.retailer');
        });
    }
}
