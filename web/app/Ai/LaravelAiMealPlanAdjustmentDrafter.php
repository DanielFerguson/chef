<?php

namespace App\Ai;

use App\Ai\Agents\MealPlanAdjustmentDraftingAgent;
use App\Ai\Contracts\MealPlanAdjustmentDrafter;
use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Ai\Data\MealPlanAdjustmentDraftResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiMealPlanAdjustmentDrafter implements MealPlanAdjustmentDrafter
{
    public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult
    {
        $response = (new MealPlanAdjustmentDraftingAgent($request))
            ->prompt('Return the coherent plan-adjustment proposals now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Meal-plan adjustment drafting did not return structured output.');
        }

        return MealPlanAdjustmentDraftResult::fromArray($response->structured);
    }
}
