<?php

namespace App\Actions\Recipes;

use App\Models\Recipe;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateRecipe
{
    public function __construct(private readonly CreateRecipeVersion $createVersion) {}

    /**
     * @param  array<int, array{name: string, quantity?: float|null, unit?: string|null, preparation?: string|null, optional?: bool}>  $ingredients
     * @param  array<int, array{instruction: string, timer_minutes?: int|null}>  $steps
     * @param  array<int, string>  $equipment
     * @param  array<int, array{kind: string, instruction: string, lead_minutes?: int|null}>  $notices
     */
    public function handle(Team $team, User $user, string $title, ?string $summary, float $servings, ?int $prepMinutes, ?int $cookMinutes, array $ingredients, array $steps, array $equipment = [], array $notices = [], ?string $notes = null, ?string $sourceUrl = null, ?string $idempotencyKey = null, ?string $storageGuidance = null): Recipe
    {
        if (! $user->memberships()->whereBelongsTo($team)->exists()) {
            throw new AuthorizationException('You cannot create recipes for this family.');
        }

        return DB::transaction(function () use ($team, $user, $title, $summary, $servings, $prepMinutes, $cookMinutes, $ingredients, $steps, $equipment, $notices, $notes, $sourceUrl, $idempotencyKey, $storageGuidance): Recipe {
            if ($idempotencyKey !== null) {
                $existing = $team->recipes()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing->load('latestVersion');
                }
            }

            $recipe = Recipe::query()->create([
                'team_id' => $team->id,
                'created_by_user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'title' => trim($title),
                'summary' => $summary,
                'source_url' => $sourceUrl,
            ]);
            $this->createVersion->handle($recipe, $user, $title, $summary, $servings, $prepMinutes, $cookMinutes, $ingredients, $steps, $equipment, $notices, $notes, $sourceUrl, $storageGuidance);

            return $recipe->load('latestVersion');
        });
    }
}
