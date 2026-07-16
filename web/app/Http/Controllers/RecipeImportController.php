<?php

namespace App\Http\Controllers;

use App\Actions\Recipes\ImportRecipeText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeImportController extends Controller
{
    public function __invoke(Request $request, ImportRecipeText $importRecipe): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $validated = $request->validate([
            'source_text' => ['required', 'string', 'max:100000'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ]);
        $recipe = $importRecipe->handle(
            $team,
            $request->user(),
            $validated['source_text'],
            $validated['source_url'] ?? null,
        );

        return to_route('recipes.show', $recipe);
    }
}
