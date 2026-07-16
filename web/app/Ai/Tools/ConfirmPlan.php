<?php

namespace App\Ai\Tools;

use App\Actions\MealPlans\ConfirmMealPlan;
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
        private readonly ConfirmMealPlan $confirmPlan,
        private readonly AssessMealPlanReadiness $assessReadiness,
    ) {}

    public function description(): Stringable|string
    {
        return 'Confirm the completed plan only after the user explicitly agrees to confirm it. Never infer confirmation from selecting the final meal.';
    }

    public function handle(Request $request): Stringable|string
    {
        $milestone = $this->confirmPlan->handle($this->mealPlan, $this->actor);

        return json_encode([
            'milestone' => $milestone,
            'plan_progress' => $this->assessReadiness->handle($this->mealPlan->refresh()),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
