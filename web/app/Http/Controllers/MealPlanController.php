<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\BuildMealPlanWorkspace;
use App\Actions\MealPlans\DeleteMealPlan;
use App\Actions\MealPlans\RenameMealPlan;
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

    public function show(
        Request $request,
        MealPlan $mealPlan,
        BuildMealPlanWorkspace $buildWorkspace,
    ): Response {
        $this->authorize('view', $mealPlan);
        $requestedPhase = $request->query('phase');
        $phase = match ($requestedPhase) {
            'calendar' => 'calendar',
            'list' => 'list',
            default => 'conversation',
        };

        return Inertia::render('meal-plans/show', [
            'workspace' => [
                ...$buildWorkspace->handle($mealPlan, $request->user()),
                'phase' => $phase,
            ],
        ]);
    }

    public function update(Request $request, MealPlan $mealPlan, RenameMealPlan $renameMealPlan): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'expected_revision' => ['nullable', 'integer', 'min:1'],
        ]);

        $renameMealPlan->handle(
            $mealPlan,
            $request->user(),
            $validated['title'],
            $validated['expected_revision'] ?? null,
        );

        return back();
    }

    public function destroy(Request $request, MealPlan $mealPlan, DeleteMealPlan $deleteMealPlan): RedirectResponse
    {
        $validated = $request->validate([
            'redirect_to_dashboard' => ['sometimes', 'boolean'],
        ]);

        $deleteMealPlan->handle($mealPlan, $request->user());

        return ($validated['redirect_to_dashboard'] ?? false)
            ? to_route('dashboard')
            : back();
    }
}
