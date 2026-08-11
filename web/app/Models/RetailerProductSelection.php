<?php

namespace App\Models;

use App\Enums\RetailerSelectionMethod;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerProductSelectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property RetailerSelectionMethod $method
 * @property float|null $confidence
 * @property bool $low_confidence
 * @property float|null $required_quantity
 * @property float|null $total_quantity
 * @property float|null $waste_quantity
 * @property list<string>|null $policy_decisions
 * @property list<string>|null $material_exceptions
 * @property Carbon $selected_at
 * @property Carbon|null $revalidated_at
 */
#[Fillable(['team_id', 'grocery_requirement_id', 'retailer_product_candidate_id', 'method', 'confidence', 'low_confidence', 'semantic_tier', 'reasoning', 'policy_decisions', 'material_exceptions', 'pack_count', 'required_quantity', 'total_quantity', 'waste_quantity', 'total_price_cents', 'selection_checksum', 'revalidation_checksum', 'selected_at', 'revalidated_at'])]
class RetailerProductSelection extends Model
{
    /** @use HasFactory<RetailerProductSelectionFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<GroceryRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(GroceryRequirement::class, 'grocery_requirement_id');
    }

    /** @return BelongsTo<RetailerProductCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(RetailerProductCandidate::class, 'retailer_product_candidate_id');
    }

    protected function casts(): array
    {
        return [
            'method' => RetailerSelectionMethod::class,
            'confidence' => 'float',
            'low_confidence' => 'boolean',
            'policy_decisions' => 'array',
            'material_exceptions' => 'array',
            'required_quantity' => 'float',
            'total_quantity' => 'float',
            'waste_quantity' => 'float',
            'selected_at' => 'datetime',
            'revalidated_at' => 'datetime',
        ];
    }
}
