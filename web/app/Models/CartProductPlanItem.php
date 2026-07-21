<?php

namespace App\Models;

use App\Enums\CartProductPlanItemStatus;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $cart_product_plan_id
 * @property int|null $shopping_list_item_id
 * @property int $position
 * @property CartProductPlanItemStatus $status
 * @property array<string, mixed> $requirement_snapshot
 * @property array<int, array<string, mixed>>|null $candidates
 * @property array<string, mixed>|null $selected_product
 * @property string|null $decision_reason
 */
#[Fillable(['team_id', 'cart_product_plan_id', 'shopping_list_item_id', 'position', 'status', 'requirement_snapshot', 'candidates', 'selected_product', 'decision_reason'])]
class CartProductPlanItem extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<CartProductPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CartProductPlan::class, 'cart_product_plan_id');
    }

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CartProductPlanItemStatus::class,
            'requirement_snapshot' => 'array',
            'candidates' => 'array',
            'selected_product' => 'array',
        ];
    }
}
