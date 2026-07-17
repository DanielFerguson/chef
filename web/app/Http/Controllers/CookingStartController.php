<?php

namespace App\Http\Controllers;

use App\Actions\Cooking\StartCooking;
use App\Models\PlannedMeal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CookingStartController extends Controller
{
    public function __invoke(Request $request, PlannedMeal $plannedMeal, StartCooking $startCooking): RedirectResponse
    {
        $startCooking->handle($plannedMeal, $request->user());

        return to_route('planned-meals.cook.show', $plannedMeal);
    }
}
