<?php

namespace App\Http\Controllers;

use App\Actions\Planning\MovePlannedMeal;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlannedMealMoveController extends Controller
{
    public function __invoke(Request $request, PlannedMeal $plannedMeal, MovePlannedMeal $move): RedirectResponse
    {
        $this->authorize('update', $plannedMeal);
        $validated = $request->validate(['meal_slot_id' => ['required', 'integer']]);
        $target = MealSlot::query()
            ->where('team_id', $plannedMeal->team_id)
            ->findOrFail((int) $validated['meal_slot_id']);
        $move->handle($plannedMeal, $target, $request->user());

        return back();
    }
}
