<?php

namespace App\Jobs;

use App\Actions\Groceries\BuildGroceryPlan;
use App\Enums\BasketRunStatus;
use App\Models\MealPlan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildGroceryPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $mealPlanId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'grocery-plan:'.$this->mealPlanId;
    }

    public function handle(BuildGroceryPlan $buildGroceryPlan): void
    {
        $groceryPlan = $buildGroceryPlan->handle(MealPlan::query()->findOrFail($this->mealPlanId));
        $run = $groceryPlan->basketRuns()->latest('id')->first();

        if ($run?->status === BasketRunStatus::DiscoveringProducts
            && config('retailer.features.discovery', false)) {
            DiscoverRetailerProductsJob::dispatch($run->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $mealPlan = MealPlan::query()->find($this->mealPlanId);
        if ($mealPlan === null) {
            return;
        }

        $mealPlan->basketRuns()
            ->whereNull('grocery_plan_id')
            ->whereIn('status', [
                BasketRunStatus::WaitingForRecipes->value,
                BasketRunStatus::BuildingRequirements->value,
            ])
            ->update([
                'status' => BasketRunStatus::Failed->value,
                'failure_code' => 'grocery_plan_build_failed',
                'failure_message' => 'Chef could not finish preparing grocery requirements. Try preparing the basket again.',
                'claim_token' => null,
                'claimed_at' => null,
            ]);
    }
}
