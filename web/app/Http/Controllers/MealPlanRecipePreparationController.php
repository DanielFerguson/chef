<?php

namespace App\Http\Controllers;

use App\Actions\Recipes\PrepareMealPlanRecipes;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealPlanRecipePreparationController extends Controller
{
    public function __invoke(Request $request, MealPlan $mealPlan, PrepareMealPlanRecipes $prepare): RedirectResponse
    {
        $prepare->handle($mealPlan, $request->user());

        return back();
    }
}
