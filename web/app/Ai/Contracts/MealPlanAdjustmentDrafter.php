<?php

namespace App\Ai\Contracts;

use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Ai\Data\MealPlanAdjustmentDraftResult;

interface MealPlanAdjustmentDrafter
{
    public function draft(MealPlanAdjustmentDraftRequest $request): MealPlanAdjustmentDraftResult;
}
