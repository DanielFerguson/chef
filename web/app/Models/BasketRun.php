<?php

namespace App\Models;

use App\Enums\BasketRunStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\BasketRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property BasketRunStatus $status
 * @property string|null $attention_kind
 * @property array<string, mixed>|null $attention_details
 * @property int|null $budget_override_cents
 * @property Carbon|null $budget_overridden_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $mutation_started_at
 * @property Carbon|null $basket_cleared_at
 * @property Carbon|null $basket_captured_at
 * @property Carbon|null $restore_requested_at
 * @property Carbon|null $restore_completed_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'grocery_plan_id', 'retailer_connection_id', 'requested_by_user_id', 'status', 'idempotency_key', 'input_fingerprint', 'claim_token', 'claimed_at', 'stagehand_fallback_count', 'mutation_started_at', 'basket_cleared_at', 'baseline_checksum', 'target_checksum', 'final_checksum', 'replaced_line_count', 'chef_subtotal_cents', 'retailer_total_cents', 'basket_captured_at', 'failure_code', 'failure_message', 'attention_kind', 'attention_details', 'budget_override_cents', 'budget_override_by_user_id', 'budget_overridden_at', 'restore_requested_at', 'restore_completed_at'])]
class BasketRun extends Model
{
    /** @use HasFactory<BasketRunFactory> */
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

    /** @return BelongsTo<GroceryPlan, $this> */
    public function groceryPlan(): BelongsTo
    {
        return $this->belongsTo(GroceryPlan::class);
    }

    /** @return BelongsTo<RetailerConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(RetailerConnection::class, 'retailer_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<BasketRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BasketRunItem::class);
    }

    /** @return HasMany<BasketSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(BasketSnapshot::class);
    }

    /** @return HasMany<MealPlanAdjustmentDraft, $this> */
    public function adjustmentDrafts(): HasMany
    {
        return $this->hasMany(MealPlanAdjustmentDraft::class);
    }

    /** @return HasMany<BasketRunStatusTransition, $this> */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(BasketRunStatusTransition::class);
    }

    protected function casts(): array
    {
        return [
            'status' => BasketRunStatus::class,
            'claimed_at' => 'datetime',
            'mutation_started_at' => 'datetime',
            'basket_cleared_at' => 'datetime',
            'basket_captured_at' => 'datetime',
            'attention_details' => 'array',
            'budget_overridden_at' => 'datetime',
            'restore_requested_at' => 'datetime',
            'restore_completed_at' => 'datetime',
        ];
    }
}
