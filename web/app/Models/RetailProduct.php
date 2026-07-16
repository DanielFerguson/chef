<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['retailer_id', 'external_id', 'name', 'brand', 'pack_quantity', 'pack_unit', 'current_price', 'currency', 'product_url', 'last_seen_at'])]
class RetailProduct extends Model
{
    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    protected function casts(): array
    {
        return ['pack_quantity' => 'float', 'current_price' => 'float', 'last_seen_at' => 'datetime'];
    }
}
