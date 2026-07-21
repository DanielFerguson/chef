<?php

namespace App\Http\Controllers;

use App\Actions\Recipes\CreateRecipeVersion;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeVersionController extends Controller
{
    public function store(Request $request, Recipe $recipe, CreateRecipeVersion $createVersion): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $validated = RecipeController::validateRecipe($request);
        $createVersion->handle(
            $recipe,
            $request->user(),
            $validated['title'],
            $validated['summary'] ?? null,
            (float) $validated['servings'],
            $validated['prep_minutes'] ?? null,
            $validated['cook_minutes'] ?? null,
            $validated['ingredients'],
            $validated['steps'],
            $validated['equipment'] ?? [],
            $validated['notices'] ?? [],
            $validated['notes'] ?? null,
            $validated['source_url'] ?? null,
            $validated['storage_guidance'] ?? null,
        );

        return back();
    }
}
