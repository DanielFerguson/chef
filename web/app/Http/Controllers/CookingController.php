<?php

namespace App\Http\Controllers;

use App\Models\PlannedMeal;
use Inertia\Inertia;
use Inertia\Response;

class CookingController extends Controller
{
    public function show(PlannedMeal $plannedMeal): Response
    {
        $this->authorize('view', $plannedMeal);
        $plannedMeal->load([
            'mealPlan:id,title,starts_on,ends_on',
            'mealSlot.participants:id,name',
            'recipeVersion.recipe:id,title',
            'recipeVersion.ingredients',
            'recipeVersion.steps',
            'recipeVersion.equipment',
            'recipeVersion.preparationNotices',
            'outcome.feedback.person:id,name',
        ]);

        return Inertia::render('cooking/show', [
            'meal' => $plannedMeal,
        ]);
    }
}
