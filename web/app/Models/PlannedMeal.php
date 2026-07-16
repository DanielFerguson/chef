<?php

namespace App\Models;

use App\Enums\PlannedMealStatus;
use App\Enums\PlannedMealType;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $team_id
 * @property int $meal_plan_id
 * @property int $meal_slot_id
 * @property int|null $meal_proposal_id
 * @property int|null $selected_by_user_id
 * @property string $title
 * @property string|null $summary
 * @property int|null $estimated_minutes
 * @property float|null $estimated_cost
 * @property PlannedMealType $type
 * @property PlannedMealStatus $status
 */
#[Fillable(['team_id', 'meal_plan_id', 'meal_slot_id', 'meal_proposal_id', 'recipe_version_id', 'source_planned_meal_id', 'selected_by_user_id', 'type', 'status', 'servings', 'title', 'summary', 'notes', 'estimated_minutes', 'estimated_cost', 'recommendation_explanation'])]
class PlannedMeal extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    /** @return BelongsTo<MealProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(MealProposal::class, 'meal_proposal_id');
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    /** @return BelongsTo<self, $this> */
    public function sourcePlannedMeal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_planned_meal_id');
    }

    /** @return HasOne<PlannedMealRecipePreparation, $this> */
    public function recipePreparation(): HasOne
    {
        return $this->hasOne(PlannedMealRecipePreparation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PlannedMealType::class,
            'status' => PlannedMealStatus::class,
            'servings' => 'float',
            'estimated_cost' => 'float',
            'recommendation_explanation' => 'array',
        ];
    }
}
