<?php

namespace App\Models;

use Database\Factories\BasketRunStatusTransitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $transitioned_at */
#[Fillable(['team_id', 'basket_run_id', 'from_status', 'to_status', 'reason_code', 'duration_ms', 'transitioned_at'])]
class BasketRunStatusTransition extends Model
{
    /** @use HasFactory<BasketRunStatusTransitionFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<BasketRun, $this> */
    public function basketRun(): BelongsTo
    {
        return $this->belongsTo(BasketRun::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }
}
