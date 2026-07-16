<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\GenerateShoppingList;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListGenerationController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, GenerateShoppingList $generate): RedirectResponse
    {
        $generate->handle($mealPlan, $request->user());

        return to_route('meal-plans.shopping.show', $mealPlan);
    }
}
