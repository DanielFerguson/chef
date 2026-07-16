<?php

namespace App\Ai\Tools;

use App\Actions\Shopping\UpdateShoppingListItem;
use App\Enums\ShoppingListItemCategory;
use App\Models\MealPlan;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdatePlanShoppingItem implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly UpdateShoppingListItem $updateItem,
    ) {}

    public function description(): Stringable|string
    {
        return 'Update one prepared shopping-list item, including its grocery category, quantity, unit, note, pantry, inclusion, or checked state. Inspect the list first.';
    }

    public function handle(Request $request): Stringable|string
    {
        $list = $this->mealPlan->shoppingList;

        if ($list === null) {
            throw ValidationException::withMessages(['shopping_list' => 'Prepare the shopping list before updating items.']);
        }

        $item = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            ->findOrFail($request->integer('item_id'));
        $changes = [];

        foreach (['name', 'category', 'quantity', 'unit', 'note', 'included', 'in_pantry', 'checked'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $changes[$field] = match ($field) {
                'quantity' => $request->float($field),
                'included', 'in_pantry', 'checked' => $request->boolean($field),
                'unit', 'note' => $request->string($field)->toString() ?: null,
                default => $request->string($field)->toString(),
            };
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['item' => 'Choose at least one item field to update.']);
        }

        $item = $this->updateItem->handle(
            $item,
            $this->actor,
            $changes,
            $request->integer('expected_revision') ?: null,
        );

        return json_encode([
            'item' => $item,
            'shopping_list_revision' => $list->refresh()->revision,
            'next_action' => 'continue_reviewing_list',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->description('Exact item identifier from InspectPlanShoppingList.')->required(),
            'name' => $schema->string()->description('Corrected item name.'),
            'category' => $schema->string()->enum(ShoppingListItemCategory::class)->description('Correct grocery category.'),
            'quantity' => $schema->number()->min(0)->description('Corrected quantity.'),
            'unit' => $schema->string()->description('Corrected unit.'),
            'note' => $schema->string()->description('Corrected note.'),
            'included' => $schema->boolean()->description('Whether the item belongs in this shop.'),
            'in_pantry' => $schema->boolean()->description('Whether the household already has the item.'),
            'checked' => $schema->boolean()->description('Whether the shopper has checked off the item.'),
            'expected_revision' => $schema->integer()->description('Current list revision from InspectPlanShoppingList. Required except for an isolated check-off.')->required(),
        ];
    }
}
