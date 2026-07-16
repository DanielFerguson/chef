<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealPlanMilestoneKind;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealPlanMilestoneController extends Controller
{
    public function store(Request $request, MealPlan $mealPlan, RecordMealPlanMilestone $recordMilestone): RedirectResponse
    {
        $validated = $request->validate(['kind' => ['required', Rule::enum(MealPlanMilestoneKind::class)]]);
        $recordMilestone->handle($mealPlan, $request->user(), MealPlanMilestoneKind::from($validated['kind']));

        return back();
    }
}
