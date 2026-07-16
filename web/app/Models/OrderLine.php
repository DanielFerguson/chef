<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'order_id', 'shopping_list_item_id', 'retail_product_id', 'retailer_product_identifier', 'product_name', 'brand', 'pack', 'quantity', 'unit_price', 'total_price', 'substituted_from_name'])]
class OrderLine extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return ['unit_price' => 'float', 'total_price' => 'float'];
    }
}
