<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\ApproveMealPlanForShopping;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanApprovalController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, ApproveMealPlanForShopping $approve): RedirectResponse
    {
        $validated = $request->validate([
            'explicitly_reviewed_safety' => ['accepted'],
            'fulfilment_method' => ['nullable', 'in:delivery,pickup'],
        ]);

        $approve->handle($mealPlan, $request->user(), $validated['fulfilment_method'] ?? null);

        return to_route('meal-plans.shopping.show', $mealPlan);
    }
}
