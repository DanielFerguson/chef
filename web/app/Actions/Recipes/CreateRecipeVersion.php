<?php

namespace App\Actions\Recipes;

use App\Enums\PreparationNoticeKind;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateRecipeVersion
{
    /**
     * @param  array<int, array{name: string, quantity?: float|null, unit?: string|null, preparation?: string|null, optional?: bool}>  $ingredients
     * @param  array<int, array{instruction: string, timer_minutes?: int|null}>  $steps
     * @param  array<int, string>  $equipment
     * @param  array<int, array{kind: string, instruction: string, lead_minutes?: int|null}>  $notices
     */
    public function handle(
        Recipe $recipe,
        User $user,
        string $title,
        ?string $summary,
        float $servings,
        ?int $prepMinutes,
        ?int $cookMinutes,
        array $ingredients,
        array $steps,
        array $equipment = [],
        array $notices = [],
        ?string $notes = null,
        ?string $sourceUrl = null,
        ?string $storageGuidance = null,
    ): RecipeVersion {
        if (! $user->memberships()->where('team_id', $recipe->team_id)->exists()) {
            throw new AuthorizationException('You cannot edit recipes for this family.');
        }

        if ($ingredients === [] || $steps === []) {
            throw ValidationException::withMessages(['recipe' => 'A recipe needs at least one ingredient and one step.']);
        }

        if (trim($title) === '') {
            throw ValidationException::withMessages(['title' => 'Give this recipe a title.']);
        }

        if (collect($ingredients)->contains(fn (array $item) => trim($item['name']) === '')) {
            throw ValidationException::withMessages(['ingredients' => 'Every ingredient needs a name.']);
        }

        if (collect($steps)->contains(fn (array $step) => trim($step['instruction']) === '')) {
            throw ValidationException::withMessages(['steps' => 'Every step needs an instruction.']);
        }

        if (collect($notices)->contains(fn (array $notice) => PreparationNoticeKind::tryFrom($notice['kind']) === null || trim($notice['instruction']) === '')) {
            throw ValidationException::withMessages(['notices' => 'Every preparation notice needs a valid kind and instruction.']);
        }

        return DB::transaction(function () use ($recipe, $user, $title, $summary, $servings, $prepMinutes, $cookMinutes, $ingredients, $steps, $equipment, $notices, $notes, $sourceUrl, $storageGuidance): RecipeVersion {
            Recipe::query()->whereKey($recipe)->lockForUpdate()->firstOrFail();
            $version = (int) $recipe->versions()->max('version') + 1;
            $recipeVersion = $recipe->versions()->create([
                'team_id' => $recipe->team_id,
                'created_by_user_id' => $user->id,
                'version' => $version,
                'title' => trim($title),
                'summary' => $summary,
                'servings' => max(0.25, $servings),
                'prep_minutes' => $prepMinutes,
                'cook_minutes' => $cookMinutes,
                'source_url' => $sourceUrl,
                'notes' => $notes,
                'storage_guidance' => $storageGuidance,
                'published_at' => now(),
            ]);

            foreach (array_values($ingredients) as $position => $item) {
                $name = trim($item['name']);
                $ingredient = Ingredient::query()->firstOrCreate(
                    ['team_id' => $recipe->team_id, 'normalized_name' => Str::lower($name)],
                    ['created_by_user_id' => $user->id, 'name' => $name],
                );
                $recipeVersion->ingredients()->create([
                    'ingredient_id' => $ingredient->id,
                    'name' => $name,
                    'quantity' => $item['quantity'] ?? null,
                    'unit' => $item['unit'] ?? null,
                    'preparation' => $item['preparation'] ?? null,
                    'optional' => $item['optional'] ?? false,
                    'position' => $position,
                ]);
            }

            foreach (array_values($steps) as $position => $step) {
                $recipeVersion->steps()->create([
                    'position' => $position,
                    'instruction' => trim($step['instruction']),
                    'timer_minutes' => $step['timer_minutes'] ?? null,
                ]);
            }

            foreach (array_values($equipment) as $position => $name) {
                $recipeVersion->equipment()->create(['position' => $position, 'name' => trim($name)]);
            }

            foreach (array_values($notices) as $position => $notice) {
                $recipeVersion->preparationNotices()->create([
                    'position' => $position,
                    'kind' => $notice['kind'],
                    'instruction' => trim($notice['instruction']),
                    'lead_minutes' => $notice['lead_minutes'] ?? null,
                ]);
            }

            $recipe->update([
                'title' => trim($title),
                'summary' => $summary,
                'source_url' => $sourceUrl ?? $recipe->source_url,
            ]);

            return $recipeVersion->load(['ingredients', 'steps', 'equipment', 'preparationNotices']);
        });
    }
}
