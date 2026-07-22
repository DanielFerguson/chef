<?php

namespace App\Models;

use App\Enums\ShoppingListItemCategory;
use App\Enums\ShoppingListItemSourceKind;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property ShoppingListItemSourceKind $source_kind
 * @property ShoppingListItemCategory $category
 */
#[Fillable(['team_id', 'shopping_list_id', 'ingredient_id', 'created_by_user_id', 'source_message_id', 'idempotency_key', 'source_kind', 'category', 'name', 'normalized_name', 'quantity', 'unit', 'note', 'included', 'in_pantry', 'checked', 'ordered_at', 'ordered_via_cart_snapshot_id', 'optional', 'estimated_price', 'position'])]
class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return HasMany<ShoppingListItemSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ShoppingListItemSource::class);
    }

    /** @return HasOne<ProductMatch, $this> */
    public function productMatch(): HasOne
    {
        return $this->hasOne(ProductMatch::class);
    }

    protected function casts(): array
    {
        return [
            'source_kind' => ShoppingListItemSourceKind::class,
            'category' => ShoppingListItemCategory::class,
            'quantity' => 'float',
            'included' => 'boolean',
            'in_pantry' => 'boolean',
            'checked' => 'boolean',
            'ordered_at' => 'datetime',
            'optional' => 'boolean',
            'estimated_price' => 'float',
        ];
    }
}
