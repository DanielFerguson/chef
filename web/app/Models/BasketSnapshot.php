<?php

namespace App\Models;

use App\Enums\BasketSnapshotKind;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\BasketSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property BasketSnapshotKind $kind
 * @property Carbon $captured_at
 */
#[Fillable(['team_id', 'basket_run_id', 'kind', 'retailer_total_cents', 'line_count', 'checksum', 'captured_at'])]
class BasketSnapshot extends Model
{
    /** @use HasFactory<BasketSnapshotFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<BasketRun, $this> */
    public function basketRun(): BelongsTo
    {
        return $this->belongsTo(BasketRun::class);
    }

    /** @return HasMany<BasketSnapshotLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BasketSnapshotLine::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => BasketSnapshotKind::class,
            'captured_at' => 'datetime',
        ];
    }
}
