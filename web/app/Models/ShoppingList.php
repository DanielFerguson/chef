<?php

namespace App\Models;

use App\Enums\ShoppingListGenerationMethod;
use App\Enums\ShoppingListGenerationStatus;
use App\Enums\ShoppingListStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array{from_plan_revision: int, to_plan_revision?: int, changes: array<int, array<string, mixed>>}|null $stale_diff
 * @property ShoppingListStatus $status
 * @property ShoppingListGenerationStatus $generation_status
 * @property ShoppingListGenerationMethod|null $last_generation_method
 * @property int $generation_attempts
 * @property Carbon|null $generation_started_at
 * @property string|null $fulfilment_method
 * @property Carbon|null $fulfilment_scheduled_for
 * @property Carbon|null $fulfilment_confirmed_at
 */
#[Fillable(['team_id', 'meal_plan_id', 'created_by_user_id', 'revision', 'source_plan_revision', 'status', 'fulfilment_method', 'fulfilment_scheduled_for', 'fulfilment_confirmed_at', 'generation_status', 'generation_token', 'generation_attempts', 'generation_context_hash', 'last_generation_method', 'generation_failure_code', 'generation_failure_message', 'generation_started_at', 'generation_completed_at', 'completed_at', 'stale_at', 'stale_reason', 'stale_diff'])]
class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
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

    /** @return HasMany<AutomationRun, $this> */
    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->latest();
    }

    /** @return HasMany<CartProductPlan, $this> */
    public function cartProductPlans(): HasMany
    {
        return $this->hasMany(CartProductPlan::class)->latest();
    }

    protected function casts(): array
    {
        return [
            'status' => ShoppingListStatus::class,
            'generation_status' => ShoppingListGenerationStatus::class,
            'last_generation_method' => ShoppingListGenerationMethod::class,
            'generation_started_at' => 'datetime',
            'generation_completed_at' => 'datetime',
            'completed_at' => 'datetime',
            'fulfilment_scheduled_for' => 'datetime',
            'fulfilment_confirmed_at' => 'datetime',
            'stale_at' => 'datetime',
            'stale_diff' => 'array',
        ];
    }
}
