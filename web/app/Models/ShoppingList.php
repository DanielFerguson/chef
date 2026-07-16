<?php

namespace App\Models;

use App\Enums\ShoppingListStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array{from_plan_revision: int, to_plan_revision?: int, changes: array<int, array<string, mixed>>}|null $stale_diff
 * @property ShoppingListStatus $status
 */
#[Fillable(['team_id', 'meal_plan_id', 'created_by_user_id', 'revision', 'source_plan_revision', 'status', 'completed_at', 'stale_at', 'stale_reason', 'stale_diff'])]
class ShoppingList extends Model
{
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<ShoppingListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)->orderBy('position');
    }

    /** @return HasMany<ShoppingListRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ShoppingListRevision::class)->orderByDesc('revision');
    }

    /** @return HasMany<ShoppingListMealResolution, $this> */
    public function mealResolutions(): HasMany
    {
        return $this->hasMany(ShoppingListMealResolution::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderByDesc('recorded_at');
    }

    protected function casts(): array
    {
        return [
            'status' => ShoppingListStatus::class,
            'completed_at' => 'datetime',
            'stale_at' => 'datetime',
            'stale_diff' => 'array',
        ];
    }
}
