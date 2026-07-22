<?php

namespace App\Ai\Tools;

use App\Actions\MealPlans\ApproveMealPlanForShopping;
use App\Actions\Planning\AssessMealPlanReadiness;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ConfirmPlan implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly ApproveMealPlanForShopping $approvePlan,
        private readonly AssessMealPlanReadiness $assessReadiness,
    ) {}

    public function description(): Stringable|string
    {
        return 'Approve the visible whole-plan draft and begin recipe and shopping preparation only after the user explicitly agrees. Never infer approval from selecting or suggesting the final meal.';
    }

    public function handle(Request $request): Stringable|string
    {
        $mealPlan = $this->approvePlan->handle($this->mealPlan, $this->actor);

        return json_encode([
            'approved' => true,
            'shopping_list_id' => $mealPlan->shoppingList?->id,
            'plan_progress' => $this->assessReadiness->handle($this->mealPlan->refresh()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
