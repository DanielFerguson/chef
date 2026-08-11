<?php

namespace App\Models;

use App\Enums\RetailerCandidateStatus;
use App\Enums\RetailerProvider;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\RetailerProductCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property RetailerProvider $provider
 * @property RetailerCandidateStatus $status
 * @property int $id
 * @property string $sku
 * @property string $title
 * @property string|null $brand
 * @property bool|null $is_home_brand
 * @property bool|null $is_organic
 * @property string $semantic_key
 * @property float|null $pack_quantity
 * @property string|null $pack_unit
 * @property int|null $price_cents
 * @property bool $available
 * @property list<string>|null $rejection_codes
 * @property array<string, mixed>|null $label_evidence
 * @property Carbon $captured_at
 */
#[Fillable(['team_id', 'grocery_requirement_id', 'provider', 'sku', 'title', 'brand', 'is_home_brand', 'is_organic', 'attribute_evidence', 'semantic_key', 'origin_host', 'product_path', 'pack_quantity', 'pack_unit', 'price_cents', 'available', 'status', 'rejection_codes', 'label_evidence', 'fingerprint', 'captured_at'])]
class RetailerProductCandidate extends Model
{
    /** @use HasFactory<RetailerProductCandidateFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<GroceryRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(GroceryRequirement::class, 'grocery_requirement_id');
    }

    /** @return HasOne<RetailerProductSelection, $this> */
    public function selection(): HasOne
    {
        return $this->hasOne(RetailerProductSelection::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => RetailerProvider::class,
            'pack_quantity' => 'float',
            'available' => 'boolean',
            'is_home_brand' => 'boolean',
            'is_organic' => 'boolean',
            'attribute_evidence' => 'array',
            'status' => RetailerCandidateStatus::class,
            'rejection_codes' => 'array',
            'label_evidence' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
