<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShoppingListController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);

        $plans = $team->mealPlans()
            ->whereNotNull('planning_confirmed_at')
            ->with(['shoppingList' => fn ($query) => $query
                ->select('id', 'meal_plan_id', 'status', 'revision', 'stale_at', 'updated_at')
                ->withCount([
                    'items as remaining_count' => fn ($items) => $items
                        ->where('included', true)
                        ->where('in_pantry', false)
                        ->where('checked', false),
                ])])
            ->latest('starts_on')
            ->get(['id', 'team_id', 'title', 'starts_on', 'ends_on', 'planning_confirmed_at']);

        return Inertia::render('shopping/index', ['plans' => $plans]);
    }

    public function show(Request $request, MealPlan $mealPlan): RedirectResponse
    {
        $this->authorize('view', $mealPlan);

        return to_route('meal-plans.show', [
            'mealPlan' => $mealPlan,
            'phase' => 'shopping',
        ]);
    }
}
