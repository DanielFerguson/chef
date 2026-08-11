<?php

namespace App\Models;

use App\Enums\GrocerySearchMethod;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\GroceryRequirementSearchAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $sequence
 * @property GrocerySearchMethod $method
 * @property string $query
 * @property int $result_count
 * @property int $eligible_result_count
 * @property string|null $reason_code
 * @property Carbon $captured_at
 */
#[Fillable(['team_id', 'grocery_requirement_id', 'sequence', 'method', 'query', 'result_count', 'eligible_result_count', 'reason_code', 'captured_at'])]
class GroceryRequirementSearchAttempt extends Model
{
    /** @use HasFactory<GroceryRequirementSearchAttemptFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<GroceryRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(GroceryRequirement::class, 'grocery_requirement_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => GrocerySearchMethod::class,
            'captured_at' => 'datetime',
        ];
    }
}
