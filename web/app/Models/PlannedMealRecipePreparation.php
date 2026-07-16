<?php

namespace App\Models;

use App\Enums\PlannedMealRecipePreparationStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PlannedMealRecipePreparationStatus $status
 * @property array<string, mixed> $input
 */
#[Fillable(['team_id', 'planned_meal_id', 'requested_by_user_id', 'recipe_version_id', 'status', 'input_fingerprint', 'input', 'attempts', 'failure_code', 'failure_message', 'started_at', 'completed_at'])]
class PlannedMealRecipePreparation extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlannedMealRecipePreparationStatus::class,
            'input' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
