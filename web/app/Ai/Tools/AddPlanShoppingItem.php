<?php

namespace App\Ai\Tools;

use App\Actions\Shopping\AddShoppingListItem;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddPlanShoppingItem implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly AddShoppingListItem $addItem,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add one household extra or staple to the current prepared shopping list. Inspect the list first and use its current revision.';
    }

    public function handle(Request $request): Stringable|string
    {
        $list = $this->mealPlan->shoppingList;

        if ($list === null) {
            throw ValidationException::withMessages(['shopping_list' => 'Prepare the shopping list before adding items.']);
        }

        $name = trim($request->string('name')->toString());

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name the shopping item.']);
        }

        $item = $this->addItem->handle(
            $list,
            $this->actor,
            $name,
            $request->has('quantity') ? $request->float('quantity') : null,
            $request->string('unit')->toString() ?: null,
            $request->string('note')->toString() ?: null,
            $request->boolean('staple'),
            $request->integer('expected_revision'),
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
            'name' => $schema->string()->description('Shopping item name.')->required(),
            'quantity' => $schema->number()->min(0)->description('Requested quantity when stated.'),
            'unit' => $schema->string()->description('Requested unit when stated.'),
            'note' => $schema->string()->description('Optional household note.'),
            'staple' => $schema->boolean()->description('Whether to remember this as a household staple.')->required(),
            'expected_revision' => $schema->integer()->description('Current list revision from InspectPlanShoppingList.')->required(),
        ];
    }
}
