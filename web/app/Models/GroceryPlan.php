<?php

namespace App\Models;

use App\Enums\GroceryPlanStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\GroceryPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property GroceryPlanStatus $status
 * @property array<string, mixed>|null $purchase_policy_snapshot
 * @property string|null $purchase_policy_fingerprint
 * @property int|null $effective_basket_target_cents
 * @property Carbon|null $built_at
 * @property Carbon|null $superseded_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'version', 'status', 'input_fingerprint', 'recipe_fingerprint', 'serving_policy', 'purchase_policy_snapshot', 'purchase_policy_fingerprint', 'effective_basket_target_cents', 'failure_code', 'failure_message', 'built_at', 'superseded_at'])]
class GroceryPlan extends Model
{
    /** @use HasFactory<GroceryPlanFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

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

    /** @return HasMany<GroceryRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(GroceryRequirement::class);
    }

    /** @return HasMany<BasketRun, $this> */
    public function basketRuns(): HasMany
    {
        return $this->hasMany(BasketRun::class);
    }

    protected function casts(): array
    {
        return [
            'status' => GroceryPlanStatus::class,
            'purchase_policy_snapshot' => 'array',
            'built_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
