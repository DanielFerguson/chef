<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\DeleteMealPlan;
use App\Actions\MealPlans\RenameMealPlan;
use App\Actions\MealPlans\StartMealPlan;
use App\Actions\Planning\AssessMealPlanReadiness;
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

    public function show(Request $request, MealPlan $mealPlan, AssessMealPlanReadiness $assessReadiness): Response
    {
        $this->authorize('view', $mealPlan);

        $mealPlan->load([
            'conversations.messages.author:id,name',
            'conversations.messages.feedback' => fn ($query) => $query->whereBelongsTo($request->user()),
            'conversations.feedback' => fn ($query) => $query->whereBelongsTo($request->user())->whereNull('message_id'),
            'slots.participants',
            'slots.plannedMeal.recipeVersion.ingredients',
            'slots.plannedMeal.recipeVersion.steps',
            'slots.plannedMeal.recipeVersion.equipment',
            'slots.plannedMeal.recipeVersion.preparationNotices',
            'slots.plannedMeal.sourcePlannedMeal',
            'proposals' => fn ($query) => $query->latest(),
            'revisions' => fn ($query) => $query->limit(20),
            'milestones',
            'shoppingList:id,meal_plan_id,status,revision,stale_at',
            'team.people.userLink',
            'team.people.preferences',
            'team.people.constraints.confirmationMessage.author:id,name',
            'team.preferences' => fn ($query) => $query->whereNull('person_id'),
            'team.constraints' => fn ($query) => $query
                ->whereNull('person_id')
                ->with('confirmationMessage.author:id,name'),
            'team.recipes.latestVersion.ingredients',
        ]);

        $conversation = $mealPlan->conversations->firstOrFail();

        return Inertia::render('meal-plans/show', [
            'workspace' => [
                'plan' => $mealPlan,
                'conversation' => $conversation,
                'household' => $mealPlan->team,
                'recipes' => $mealPlan->team->recipes,
                'readiness' => $assessReadiness->handle($mealPlan),
                'voice_test_mode' => app()->environment('testing')
                    && $request->boolean('_voice_test')
                    && $request->hasValidRelativeSignature(),
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
