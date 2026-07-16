<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\SetShoppingBudget;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingBudgetController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, SetShoppingBudget $setBudget): RedirectResponse
    {
        $this->authorize('update', $mealPlan);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'household_default' => ['required', 'boolean'],
        ]);
        $setBudget->handle($mealPlan, $request->user(), $validated['amount'], $validated['household_default']);

        return back();
    }
}
