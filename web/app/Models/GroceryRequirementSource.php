<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\GroceryRequirementSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property float|null $source_quantity
 * @property float|null $scaled_quantity
 * @property float $serving_factor
 */
#[Fillable(['team_id', 'grocery_requirement_id', 'planned_meal_id', 'recipe_version_id', 'recipe_ingredient_id', 'source_name', 'preparation', 'source_quantity', 'scaled_quantity', 'serving_factor', 'unit'])]
class GroceryRequirementSource extends Model
{
    /** @use HasFactory<GroceryRequirementSourceFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<GroceryRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(GroceryRequirement::class, 'grocery_requirement_id');
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    /** @return BelongsTo<RecipeIngredient, $this> */
    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }

    protected function casts(): array
    {
        return [
            'source_quantity' => 'float',
            'scaled_quantity' => 'float',
            'serving_factor' => 'float',
        ];
    }
}
