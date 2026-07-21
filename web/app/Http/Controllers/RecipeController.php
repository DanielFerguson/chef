<?php

namespace App\Http\Controllers;

use App\Actions\Recipes\CreateRecipe;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecipeController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);

        return Inertia::render('recipes/index', [
            'recipes' => $team->recipes()
                ->with(['latestVersion.ingredients', 'latestVersion.preparationNotices'])
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function store(Request $request, CreateRecipe $createRecipe): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team !== null, 404);
        $this->authorize('create', [Recipe::class, $team]);
        $validated = $this->validateRecipe($request);
        $recipe = $createRecipe->handle(
            $team,
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
            storageGuidance: $validated['storage_guidance'] ?? null,
        );

        return to_route('recipes.show', $recipe);
    }

    public function show(Recipe $recipe): Response
    {
        $this->authorize('view', $recipe);
        $recipe->load([
            'versions' => fn ($query) => $query->orderByDesc('version'),
            'versions.ingredients',
            'versions.steps',
            'versions.equipment',
            'versions.preparationNotices',
        ]);

        return Inertia::render('recipes/show', ['recipe' => $recipe]);
    }

    /** @return array<string, mixed> */
    public static function validateRecipe(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'servings' => ['required', 'numeric', 'min:0.25', 'max:999'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'storage_guidance' => ['nullable', 'string', 'max:2000'],
            'ingredients' => ['required', 'array', 'min:1', 'max:200'],
            'ingredients.*.name' => ['required', 'string', 'max:255'],
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:80'],
            'ingredients.*.preparation' => ['nullable', 'string', 'max:255'],
            'ingredients.*.optional' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:200'],
            'steps.*.instruction' => ['required', 'string', 'max:10000'],
            'steps.*.timer_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'equipment' => ['array', 'max:100'],
            'equipment.*' => ['required', 'string', 'max:255'],
            'notices' => ['array', 'max:100'],
            'notices.*.kind' => ['required', Rule::in(['defrost', 'marinate', 'soak', 'rest', 'advance_prep', 'other'])],
            'notices.*.instruction' => ['required', 'string', 'max:2000'],
            'notices.*.lead_minutes' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
