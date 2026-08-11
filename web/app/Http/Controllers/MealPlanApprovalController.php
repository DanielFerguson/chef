<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\ApproveMealPlan;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanApprovalController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, ApproveMealPlan $approve): RedirectResponse
    {
        $approve->handle($mealPlan, $request->user());

        return to_route('meal-plans.show', $mealPlan);
    }
}
