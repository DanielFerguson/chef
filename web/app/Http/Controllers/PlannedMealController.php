<?php

namespace App\Http\Controllers;

use App\Actions\Planning\UpdatePlannedMeal;
use App\Enums\PlannedMealStatus;
use App\Models\PlannedMeal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlannedMealController extends Controller
{
    public function update(Request $request, PlannedMeal $plannedMeal, UpdatePlannedMeal $updateMeal): RedirectResponse
    {
        $this->authorize('update', $plannedMeal);
        $validated = $request->validate([
            'servings' => ['required', 'numeric', 'gt:0', 'max:999'],
            'status' => ['required', Rule::enum(PlannedMealStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);
        $updateMeal->handle(
            $plannedMeal,
            $request->user(),
            (float) $validated['servings'],
            PlannedMealStatus::from($validated['status']),
            $validated['notes'] ?? null,
            $validated['expected_revision'] ?? null,
        );

        return back();
    }
}
