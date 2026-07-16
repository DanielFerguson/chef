<?php

namespace App\Http\Controllers;

use App\Actions\Planning\CreateMealSlot;
use App\Enums\MealSlotKind;
use App\Models\MealPlan;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealSlotController extends Controller
{
    public function store(Request $request, MealPlan $mealPlan, CreateMealSlot $createMealSlot): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::enum(MealSlotKind::class)],
            'label' => ['nullable', 'string', 'max:80'],
            'participant_ids' => ['array'],
            'participant_ids.*' => ['integer'],
        ]);
        $participants = Person::query()
            ->where('team_id', $mealPlan->team_id)
            ->whereIn('id', $validated['participant_ids'] ?? [])
            ->get();

        $createMealSlot->handle(
            $mealPlan,
            $request->user(),
            now()->parse($validated['date'])->startOfDay(),
            MealSlotKind::from($validated['kind']),
            $participants,
            $validated['label'] ?? null,
        );

        return back();
    }
}
