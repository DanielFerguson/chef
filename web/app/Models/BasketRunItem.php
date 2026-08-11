<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\BasketRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $verified_at */
#[Fillable(['team_id', 'basket_run_id', 'grocery_requirement_id', 'retailer_product_selection_id', 'sku', 'product_title', 'absolute_quantity', 'unit_price_cents', 'line_price_cents', 'pack_reasoning', 'verification_checksum', 'verified_at'])]
class BasketRunItem extends Model
{
    /** @use HasFactory<BasketRunItemFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<BasketRun, $this> */
    public function basketRun(): BelongsTo
    {
        return $this->belongsTo(BasketRun::class);
    }

    /** @return BelongsTo<GroceryRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(GroceryRequirement::class, 'grocery_requirement_id');
    }

    /** @return BelongsTo<RetailerProductSelection, $this> */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(RetailerProductSelection::class, 'retailer_product_selection_id');
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
