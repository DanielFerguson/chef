<?php

namespace App\Http\Controllers;

use App\Actions\Cooking\RecordMealOutcome;
use App\Enums\MealOutcomeStatus;
use App\Models\PlannedMeal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealOutcomeController extends Controller
{
    public function update(Request $request, PlannedMeal $plannedMeal, RecordMealOutcome $recordOutcome): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(MealOutcomeStatus::class)],
            'replacement_title' => ['nullable', 'string', 'max:160'],
            'postponed_until' => ['nullable', 'date'],
            'leftover_servings' => ['nullable', 'numeric', 'gt:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $recordOutcome->handle(
            $plannedMeal,
            $request->user(),
            MealOutcomeStatus::from($validated['status']),
            $validated['replacement_title'] ?? null,
            $validated['postponed_until'] ?? null,
            isset($validated['leftover_servings']) ? (float) $validated['leftover_servings'] : null,
            $validated['notes'] ?? null,
        );

        return back();
    }
}
