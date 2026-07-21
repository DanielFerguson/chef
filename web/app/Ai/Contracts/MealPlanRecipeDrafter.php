<?php

namespace App\Ai\Contracts;

use App\Ai\Data\MealPlanRecipeDraft;
use App\Ai\Data\MealPlanRecipeDraftRequest;

interface MealPlanRecipeDrafter
{
    public function draft(MealPlanRecipeDraftRequest $request): MealPlanRecipeDraft;
}
