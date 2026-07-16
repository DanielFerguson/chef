<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'shopping_list_id', 'retailer_id', 'recorded_by_user_id', 'shopping_list_revision', 'status', 'currency', 'estimated_total', 'actual_total', 'recorded_at'])]
class Order extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<Retailer, $this> */
    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    protected function casts(): array
    {
        return ['estimated_total' => 'float', 'actual_total' => 'float', 'recorded_at' => 'datetime'];
    }
}
