<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\ReviewMealPlanSafety;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanSafetyReviewController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, ReviewMealPlanSafety $reviewSafety): RedirectResponse
    {
        $this->authorize('update', $mealPlan);
        $request->validate([
            'explicitly_reviewed' => ['accepted'],
        ]);
        $reviewSafety->handle($mealPlan, $request->user());

        return back();
    }
}
