<?php

namespace App\Jobs;

use App\Actions\Recipes\MaterializePlannedMealRecipe;
use App\Models\PlannedMealRecipePreparation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterializePlannedMealRecipeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [2, 10, 30];

    public function __construct(public readonly int $preparationId)
    {
        $this->onQueue('ai');
    }

    public function handle(MaterializePlannedMealRecipe $materialize): void
    {
        $materialize->handle(PlannedMealRecipePreparation::query()->findOrFail($this->preparationId));
    }
}
