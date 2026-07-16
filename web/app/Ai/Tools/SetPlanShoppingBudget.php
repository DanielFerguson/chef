<?php

namespace App\Ai\Tools;

use App\Actions\Shopping\SetShoppingBudget;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetPlanShoppingBudget implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly SetShoppingBudget $setBudget,
    ) {}

    public function description(): Stringable|string
    {
        return 'Set the optional shopping budget for this plan, and optionally remember it as the household default.';
    }

    public function handle(Request $request): Stringable|string
    {
        $budget = $this->setBudget->handle(
            $this->mealPlan,
            $this->actor,
            $request->float('amount'),
            $request->boolean('household_default'),
        );

        return json_encode([
            'budget' => $budget,
            'next_action' => 'continue_reviewing_list',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->min(0.01)->description('Budget amount in AUD.')->required(),
            'household_default' => $schema->boolean()->description('Whether to use this for later plans by default.')->required(),
        ];
    }
}
