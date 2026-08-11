<?php

namespace App\Listeners;

use App\Events\MealPlanRecipesReady;
use App\Jobs\BuildGroceryPlanJob;

class QueueGroceryPlanBuild
{
    public function handle(MealPlanRecipesReady $event): void
    {
        if (! config('retailer.features.experience', false)) {
            return;
        }

        BuildGroceryPlanJob::dispatch($event->mealPlanId);
    }
}
