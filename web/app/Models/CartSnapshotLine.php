<?php

namespace App\Models;

use App\Enums\CartLineClassification;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $team_id
 * @property int $cart_snapshot_id
 * @property int|null $automation_run_item_id
 * @property int|null $shopping_list_item_id
 * @property CartLineClassification $classification
 * @property string|null $external_product_id
 * @property string $product_name
 * @property numeric-string|null $quantity
 * @property string|null $unit
 * @property numeric-string|null $unit_price
 * @property numeric-string|null $total_price
 * @property bool $pre_existing
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['team_id', 'cart_snapshot_id', 'automation_run_item_id', 'shopping_list_item_id', 'classification', 'external_product_id', 'product_name', 'quantity', 'unit', 'unit_price', 'total_price', 'pre_existing', 'metadata'])]
class CartSnapshotLine extends Model
{
    use ResolvesWithinCurrentTeam;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cart snapshot lines are immutable.'));
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<CartSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CartSnapshot::class, 'cart_snapshot_id');
    }

    /** @return BelongsTo<AutomationRunItem, $this> */
    public function runItem(): BelongsTo
    {
        return $this->belongsTo(AutomationRunItem::class, 'automation_run_item_id');
    }

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    protected function casts(): array
    {
        return [
            'classification' => CartLineClassification::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'pre_existing' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
