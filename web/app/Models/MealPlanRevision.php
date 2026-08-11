<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $changes
 */
#[Fillable(['team_id', 'meal_plan_id', 'user_id', 'revision', 'summary', 'changes'])]
class MealPlanRevision extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
