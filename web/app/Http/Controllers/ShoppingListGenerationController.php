<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\MealPlanSafetyContext;
use App\Actions\Shopping\PrepareMealPlanShoppingList;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListGenerationController extends Controller
{
    public function __invoke(
        Request $request,
        MealPlan $mealPlan,
        PrepareMealPlanShoppingList $prepare,
        MealPlanSafetyContext $safetyContext,
    ): RedirectResponse {
        $safetyFingerprint = $safetyContext->fingerprint($mealPlan);
        $confirmedSafetyIsCurrent = is_string($mealPlan->confirmed_safety_context_hash)
            && hash_equals($mealPlan->confirmed_safety_context_hash, $safetyFingerprint);

        if ($mealPlan->planning_confirmed_at === null || ! $confirmedSafetyIsCurrent) {
            return to_route('meal-plans.show', $mealPlan);
        }

        $prepare->handle($mealPlan, $request->user());

        return to_route('meal-plans.show', [
            'mealPlan' => $mealPlan,
            'phase' => 'shopping',
        ]);
    }
}
