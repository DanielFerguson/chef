<?php

namespace App\Models;

use App\Enums\MealPlanAdjustmentDraftStatus;
use App\Enums\MealPlanAdjustmentKind;
use Database\Factories\MealPlanAdjustmentDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property MealPlanAdjustmentKind $kind
 * @property MealPlanAdjustmentDraftStatus $status
 * @property Carbon $generated_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $superseded_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'basket_run_id', 'originating_plan_revision', 'kind', 'status', 'input_fingerprint', 'generated_at', 'applied_at', 'superseded_at'])]
class MealPlanAdjustmentDraft extends Model
{
    /** @use HasFactory<MealPlanAdjustmentDraftFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return BelongsTo<BasketRun, $this> */
    public function basketRun(): BelongsTo
    {
        return $this->belongsTo(BasketRun::class);
    }

    /** @return HasMany<MealPlanAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MealPlanAdjustmentItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => MealPlanAdjustmentKind::class,
            'status' => MealPlanAdjustmentDraftStatus::class,
            'generated_at' => 'datetime',
            'applied_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
