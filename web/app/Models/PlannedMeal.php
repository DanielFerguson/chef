<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
#[Fillable(['team_id', 'meal_plan_id', 'meal_slot_id', 'meal_proposal_id', 'selected_by_user_id', 'title', 'summary', 'estimated_minutes', 'estimated_cost'])]
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_cost' => 'float'];
    }
}
