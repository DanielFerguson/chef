<?php

namespace App\Ai\Tools;

use App\Actions\Shopping\AddShoppingListItems;
use App\Models\MealPlan;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

#[Strict]
class AddPlanShoppingItems implements Tool
{
    public function __construct(
        private readonly MealPlan $mealPlan,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly AddShoppingListItems $addItems,
    ) {}

    public function description(): Stringable|string
    {
        return 'Atomically add one or more household extras or staples to the current prepared shopping list. Put every item requested in the current message into one call.';
    }

    public function handle(Request $request): Stringable|string
    {
        $list = $this->mealPlan->shoppingList;

        if ($list === null) {
            throw ValidationException::withMessages(['shopping_list' => 'Prepare the shopping list before adding items.']);
        }

        $items = $this->addItems->handle(
            $list,
            $this->actor,
            $this->sourceMessage,
            $request->array('items'),
        );

        return json_encode([
            'items' => $items,
            'shopping_list_revision' => $list->refresh()->revision,
            'next_action' => 'continue_reviewing_list',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->min(1)->max(50)->items($schema->object([
                'name' => $schema->string()->min(1)->max(255)->description('Shopping item name.')->required(),
                'quantity' => $schema->number()->min(0)->description('Requested quantity when stated.')->nullable()->required(),
                'unit' => $schema->string()->max(80)->description('Requested unit when stated.')->nullable()->required(),
                'note' => $schema->string()->max(2000)->description('Optional household note.')->nullable()->required(),
                'staple' => $schema->boolean()->description('Whether to remember this as a household staple.')->required(),
            ])->withoutAdditionalProperties())->description('Every household item requested in the current user message.')->required(),
        ];
    }
}
