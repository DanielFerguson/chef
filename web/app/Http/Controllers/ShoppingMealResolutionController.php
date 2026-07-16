<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\ResolvePlannedMealIngredients;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingMealResolutionController extends Controller
{
    public function __invoke(Request $request, ShoppingList $shoppingList, PlannedMeal $plannedMeal, ResolvePlannedMealIngredients $resolve): RedirectResponse
    {
        $validated = $request->validate([
            'ingredients' => ['required', 'array', 'min:1', 'max:50'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $resolve->handle($shoppingList, $plannedMeal, $request->user(), $validated['ingredients'], $validated['expected_revision']);

        return back();
    }
}
