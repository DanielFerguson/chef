<?php

namespace App\Jobs;

use App\Actions\Recipes\MaterializeMealPlanRecipes;
use App\Models\MealPlan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializeMealPlanRecipesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $mealPlanId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'meal-plan-recipe-batch:'.$this->mealPlanId;
    }

    public function handle(MaterializeMealPlanRecipes $materialize): void
    {
        $materialize->handle(MealPlan::query()->findOrFail($this->mealPlanId));
    }
}
