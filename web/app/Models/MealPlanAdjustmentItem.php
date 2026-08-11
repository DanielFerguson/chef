<?php

namespace App\Models;

use Database\Factories\MealPlanAdjustmentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property list<int> $covered_requirement_ids */
#[Fillable(['team_id', 'meal_plan_adjustment_draft_id', 'meal_slot_id', 'planned_meal_id', 'meal_proposal_id', 'replacement_title', 'replacement_summary', 'estimated_minutes', 'estimated_cost_cents', 'covered_requirement_ids'])]
class MealPlanAdjustmentItem extends Model
{
    /** @use HasFactory<MealPlanAdjustmentItemFactory> */
    use HasFactory;

    /** @return BelongsTo<MealPlanAdjustmentDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MealPlanAdjustmentDraft::class, 'meal_plan_adjustment_draft_id');
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** @return BelongsTo<MealProposal, $this> */
    public function mealProposal(): BelongsTo
    {
        return $this->belongsTo(MealProposal::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['covered_requirement_ids' => 'array'];
    }
}
