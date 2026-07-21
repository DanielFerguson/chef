<?php

namespace App\Ai;

use App\Ai\Agents\MealPlanRecipeDraftingAgent;
use App\Ai\Contracts\MealPlanRecipeDrafter;
use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiMealPlanRecipeDrafter implements MealPlanRecipeDrafter
{
    public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft
    {
        $response = (new MealPlanRecipeDraftingAgent($request))->prompt('Prepare every recipe for this completed meal plan now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Meal-plan recipe drafting did not return structured output.');
        }

        return MealPlanRecipeDraft::fromArray($response->structured);
    }
}
