<?php

namespace App\Jobs;

use App\Actions\Automation\ContinueApprovedShopping;
use App\Actions\Shopping\GenerateShoppingList;
use App\Enums\MealPlanRecipeGenerationStatus;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinishPreparingShoppingListJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $mealPlanId,
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return 'meal-plan-shopping:'.$this->mealPlanId;
    }

    public function backoff(): int
    {
        return 2;
    }

    public function handle(GenerateShoppingList $generate, ContinueApprovedShopping $continueApprovedShopping): void
    {
        $mealPlan = MealPlan::query()->findOrFail($this->mealPlanId);
        $unresolved = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->where('type', 'custom')
            ->whereNull('recipe_version_id')
            ->get();

        if ($unresolved->isNotEmpty() && $mealPlan->recipe_generation_status === MealPlanRecipeGenerationStatus::Failed) {
            return;
        }

        if ($unresolved->isNotEmpty()) {
            $this->release(2);

            return;
        }

        $shoppingList = $mealPlan->shoppingList;
        $claimRetryAfter = $shoppingList === null
            ? null
            : $generate->activeClaimRetryAfter($shoppingList);

        if ($claimRetryAfter !== null) {
            $this->release($claimRetryAfter);

            return;
        }

        $user = User::query()->findOrFail($this->userId);
        $generate->handle($mealPlan, $user);
        $continueApprovedShopping->handle($mealPlan->refresh(), $user);
    }
}
