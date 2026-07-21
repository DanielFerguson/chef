<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ShoppingListItem> */
class ShoppingListItemFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->word().' '.fake()->word();

        return [
            'shopping_list_id' => ShoppingList::factory(),
            'team_id' => fn (array $attributes) => ShoppingList::query()->findOrFail((int) $attributes['shopping_list_id'])->team_id,
            'created_by_user_id' => null,
            'source_kind' => 'manual',
            'category' => 'other',
            'name' => Str::headline($name),
            'normalized_name' => Str::lower($name),
            'quantity' => 1,
            'unit' => 'item',
            'included' => true,
            'in_pantry' => false,
            'checked' => false,
            'optional' => false,
            'position' => 1,
        ];
    }
}
