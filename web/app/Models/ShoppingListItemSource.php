<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'shopping_list_item_id', 'planned_meal_id', 'recipe_ingredient_id', 'quantity', 'unit'])]
class ShoppingListItemSource extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class, 'shopping_list_item_id');
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** @return BelongsTo<RecipeIngredient, $this> */
    public function recipeIngredient(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
