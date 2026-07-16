<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\StartMealPlan;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MealPlanController extends Controller
{
    public function store(Request $request, StartMealPlan $startMealPlan): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $user = $request->user();
        $team = $user->currentTeam;
        abort_unless($team !== null, 404);

        $startsOn = isset($validated['starts_on']) ? now()->parse($validated['starts_on'])->startOfDay() : today();
        $endsOn = isset($validated['ends_on']) ? now()->parse($validated['ends_on'])->startOfDay() : $startsOn->clone()->addDays(6);
        $mealPlan = $startMealPlan->handle(
            $team,
            $user,
            $startsOn,
            $endsOn,
            $validated['title'] ?? null,
        );

        return to_route('meal-plans.show', $mealPlan);
    }

    public function show(Request $request, MealPlan $mealPlan): Response
    {
        $this->authorize('view', $mealPlan);

        $mealPlan->load([
            'conversations.messages.author:id,name',
            'slots.participants',
            'slots.plannedMeal',
            'proposals' => fn ($query) => $query->latest(),
            'team.people.preferences',
            'team.people.constraints',
            'team.preferences',
            'team.constraints',
        ]);

        $conversation = $mealPlan->conversations->firstOrFail();

        return Inertia::render('meal-plans/show', [
            'workspace' => [
                'plan' => $mealPlan,
                'conversation' => $conversation,
                'household' => $mealPlan->team,
            ],
        ]);
    }
}
