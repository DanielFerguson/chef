<?php

namespace App\Models;

use App\Enums\MealProposalStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $meal_plan_id
 * @property int|null $meal_slot_id
 * @property int|null $message_id
 * @property int|null $proposed_by_user_id
 * @property string $title
 * @property string|null $summary
 * @property int|null $estimated_minutes
 * @property float|null $estimated_cost
 * @property MealProposalStatus $status
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'meal_slot_id', 'message_id', 'idempotency_key', 'proposed_by_user_id', 'title', 'summary', 'estimated_minutes', 'estimated_cost', 'status', 'decided_by_user_id', 'decided_at'])]
class MealProposal extends Model
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estimated_cost' => 'float',
            'status' => MealProposalStatus::class,
            'decided_at' => 'datetime',
        ];
    }
}
