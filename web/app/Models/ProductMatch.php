<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'shopping_list_item_id', 'retail_product_id', 'product_preference_id', 'selected_by_user_id', 'pack_count', 'estimated_total', 'status', 'rationale', 'preferred', 'selected_at'])]
class ProductMatch extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    /** @return BelongsTo<RetailProduct, $this> */
    public function retailProduct(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class);
    }

    /** @return BelongsTo<ProductPreference, $this> */
    public function preference(): BelongsTo
    {
        return $this->belongsTo(ProductPreference::class, 'product_preference_id');
    }

    protected function casts(): array
    {
        return ['estimated_total' => 'float', 'preferred' => 'boolean', 'selected_at' => 'datetime'];
    }
}
