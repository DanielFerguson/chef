<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MealPlans\BuildMealPlanWorkspace;
use App\Actions\MealPlans\StartMealPlan;
use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealPlanController extends Controller
{
    public function store(
        Request $request,
        StartMealPlan $startMealPlan,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $user = $request->user();
        $team = $user->currentTeam;
        abort_unless($team !== null, 404);
        $this->authorize('view', $team);

        $startsOn = isset($validated['starts_on']) ? now()->parse($validated['starts_on'])->startOfDay() : today();
        $endsOn = isset($validated['ends_on']) ? now()->parse($validated['ends_on'])->startOfDay() : $startsOn->clone()->addDays(6);
        $mealPlan = $startMealPlan->handle(
            $team,
            $user,
            $startsOn,
            $endsOn,
            $validated['title'] ?? null,
        );

        return response()->json([
            'workspace' => $buildWorkspace->handle($mealPlan, $user),
        ], 201);
    }

    public function show(
        Request $request,
        MealPlan $mealPlan,
        BuildMealPlanWorkspace $buildWorkspace,
    ): JsonResponse {
        $this->authorize('view', $mealPlan);

        return response()->json([
            'workspace' => $buildWorkspace->handle($mealPlan, $request->user()),
        ]);
    }
}
