<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Enums\RetailerConnectionStatus;
use App\Jobs\DiscoverRetailerProductsJob;
use App\Jobs\ProbeRetailerConnectionJob;
use App\Models\RetailerConnection;

class ResumeRetailerConnectionAutomation
{
    public function handle(RetailerConnection $connection): void
    {
        $connection->refresh();
        if ($connection->status !== RetailerConnectionStatus::Connected
            || $connection->active_session_id !== null
            || $connection->active_session_claim_token !== null
            || $connection->browserbase_context_id === null) {
            return;
        }

        $runs = $connection->basketRuns()
            ->whereIn('status', [
                BasketRunStatus::WaitingForRecipes->value,
                BasketRunStatus::BuildingRequirements->value,
                BasketRunStatus::DiscoveringProducts->value,
            ])
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            ProbeRetailerConnectionJob::dispatch($connection->id, $run->id);

            if ($run->status === BasketRunStatus::DiscoveringProducts
                && $run->grocery_plan_id !== null
                && config('retailer.features.discovery', false)) {
                DiscoverRetailerProductsJob::dispatch($run->id);
            }
        }
    }
}
