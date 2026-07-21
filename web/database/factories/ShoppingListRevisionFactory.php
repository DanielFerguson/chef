<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShoppingListRevision> */
class ShoppingListRevisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'team_id' => fn (array $attributes) => ShoppingList::query()->findOrFail((int) $attributes['shopping_list_id'])->team_id,
            'user_id' => null,
            'revision' => 1,
            'summary' => 'Shopping list prepared',
            'snapshot' => [
                'source_plan_revision' => 1,
                'status' => 'draft',
                'items' => [],
            ],
        ];
    }
}
