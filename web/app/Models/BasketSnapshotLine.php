<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\BasketSnapshotLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'basket_snapshot_id', 'sku', 'product_title', 'absolute_quantity', 'unit_price_cents', 'line_price_cents', 'checksum'])]
class BasketSnapshotLine extends Model
{
    /** @use HasFactory<BasketSnapshotLineFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<BasketSnapshot, $this> */
    public function basketSnapshot(): BelongsTo
    {
        return $this->belongsTo(BasketSnapshot::class);
    }
}
