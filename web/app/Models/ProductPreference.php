<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $normalized_item_name
 * @property bool $accept_substitutes
 * @property float|null $maximum_price
 */
#[Fillable(['team_id', 'identity_key', 'retailer_id', 'ingredient_id', 'normalized_item_name', 'preferred_brand', 'preferred_pack', 'accept_substitutes', 'maximum_price', 'note'])]
class ProductPreference extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    protected function casts(): array
    {
        return ['accept_substitutes' => 'boolean', 'maximum_price' => 'float'];
    }
}
