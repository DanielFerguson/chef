<?php

namespace App\Http\Controllers;

use App\Actions\Cooking\RecordMealFeedback;
use App\Enums\MealFeedbackRating;
use App\Models\MealOutcome;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealFeedbackController extends Controller
{
    public function update(Request $request, MealOutcome $mealOutcome, Person $person, RecordMealFeedback $recordFeedback): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', Rule::enum(MealFeedbackRating::class)],
            'portion' => ['nullable', Rule::in(['too_small', 'right', 'too_large'])],
            'effort' => ['nullable', Rule::in(['easy', 'right', 'too_much'])],
            'cost' => ['nullable', Rule::in(['good_value', 'right', 'too_high'])],
            'leftovers' => ['nullable', Rule::in(['none', 'some', 'plenty'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'recipe_adjustment' => ['nullable', 'string', 'max:5000'],
        ]);
        $recordFeedback->handle(
            $mealOutcome,
            $person,
            $request->user(),
            MealFeedbackRating::from($validated['rating']),
            $validated['portion'] ?? null,
            $validated['effort'] ?? null,
            $validated['cost'] ?? null,
            $validated['leftovers'] ?? null,
            $validated['notes'] ?? null,
            $validated['recipe_adjustment'] ?? null,
        );

        return back();
    }
}
