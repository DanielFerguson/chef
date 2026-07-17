<?php

namespace App\Models;

use App\Enums\MealOutcomeStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $meal_plan_id
 * @property int $planned_meal_id
 * @property MealOutcomeStatus|null $status
 * @property int $current_step_position
 * @property Carbon|null $postponed_until
 * @property float|null $leftover_servings
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'planned_meal_id', 'recorded_by_user_id', 'status', 'current_step_position', 'replacement_title', 'postponed_until', 'leftover_servings', 'notes', 'started_at', 'completed_at'])]
class MealOutcome extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** @return HasMany<MealFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(MealFeedback::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MealOutcomeStatus::class,
            'postponed_until' => 'date:Y-m-d',
            'leftover_servings' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
