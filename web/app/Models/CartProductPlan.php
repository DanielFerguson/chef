<?php

namespace App\Models;

use App\Enums\CartProductPlanStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int $shopping_list_id
 * @property int $shopping_list_revision_id
 * @property int $retailer_id
 * @property int|null $automation_run_id
 * @property CartProductPlanStatus $status
 * @property string $input_checksum
 * @property string $safety_fingerprint
 * @property array<string, mixed>|null $snapshot
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $frozen_at
 */
#[Fillable(['team_id', 'shopping_list_id', 'shopping_list_revision_id', 'retailer_id', 'automation_run_id', 'status', 'input_checksum', 'safety_fingerprint', 'snapshot', 'reviewed_by_user_id', 'reviewed_at', 'frozen_at'])]
class CartProductPlan extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<ShoppingListRevision, $this> */
    public function shoppingListRevision(): BelongsTo
    {
        return $this->belongsTo(ShoppingListRevision::class);
    }

    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /** @return BelongsTo<AutomationRun, $this> */
    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return HasMany<CartProductPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartProductPlanItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'status' => CartProductPlanStatus::class,
            'snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }
}
