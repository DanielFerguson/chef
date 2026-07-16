<?php

namespace App\Http\Controllers;

use App\Actions\Planning\UpdateMealSlotParticipants;
use App\Models\MealSlot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MealSlotParticipantController extends Controller
{
    public function update(Request $request, MealSlot $mealSlot, UpdateMealSlotParticipants $updateParticipants): RedirectResponse
    {
        $validated = $request->validate([
            'participants' => ['required', 'array', 'min:1'],
            'participants.*.person_id' => ['required', 'integer'],
            'participants.*.servings' => ['required', 'numeric', 'gt:0', 'max:999'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);
        $servings = [];

        foreach ($validated['participants'] as $participant) {
            $servings[(int) $participant['person_id']] = (float) $participant['servings'];
        }
        $updateParticipants->handle($mealSlot, $request->user(), $servings, $validated['expected_revision'] ?? null);

        return back();
    }
}
