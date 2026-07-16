<?php

namespace App\Http\Controllers;

use App\Actions\MealPlans\ConfirmMealPlan;
use App\Actions\MealPlans\RecordMealPlanMilestone;
use App\Enums\MealPlanMilestoneKind;
use App\Models\MealPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealPlanMilestoneController extends Controller
{
    public function store(Request $request, MealPlan $mealPlan, RecordMealPlanMilestone $recordMilestone, ConfirmMealPlan $confirmPlan): RedirectResponse
    {
        $validated = $request->validate(['kind' => ['required', Rule::enum(MealPlanMilestoneKind::class)]]);
        $kind = MealPlanMilestoneKind::from($validated['kind']);

        if ($kind === MealPlanMilestoneKind::PlanningConfirmed) {
            $confirmPlan->handle($mealPlan, $request->user());
        } else {
            $recordMilestone->handle($mealPlan, $request->user(), $kind);
        }

        return back();
    }
}
