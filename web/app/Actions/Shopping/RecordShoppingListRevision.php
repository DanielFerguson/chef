<?php

namespace App\Actions\Shopping;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordShoppingListRevision
{
    public function handle(ShoppingList $shoppingList, User $user, string $summary, ?int $expectedRevision = null): ShoppingListRevision
    {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot update this shopping list.');
        }

        return DB::transaction(function () use ($shoppingList, $user, $summary, $expectedRevision): ShoppingListRevision {
            $locked = ShoppingList::query()->lockForUpdate()->findOrFail($shoppingList->id);

            if ($expectedRevision !== null && $locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This shopping list changed elsewhere. Refresh it before making that change.',
                ]);
            }

            $nextRevision = $locked->revision + 1;
            $locked->update(['revision' => $nextRevision]);
            $locked->load('items.sources', 'items.productMatch.retailProduct');

            return $locked->revisions()->create([
                'team_id' => $locked->team_id,
                'user_id' => $user->id,
                'revision' => $nextRevision,
                'summary' => $summary,
                'snapshot' => [
                    'source_plan_revision' => $locked->source_plan_revision,
                    'generation_method' => $locked->getRawOriginal('last_generation_method'),
                    'status' => $locked->getRawOriginal('status'),
                    'items' => $locked->items->map(fn (ShoppingListItem $item): array => [
                        'id' => $item->id,
                        'source_kind' => $item->getRawOriginal('source_kind'),
                        'source_message_id' => $item->source_message_id,
                        'category' => $item->getRawOriginal('category'),
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'note' => $item->note,
                        'included' => $item->included,
                        'in_pantry' => $item->in_pantry,
                        'checked' => $item->checked,
                        'optional' => $item->optional,
                        'estimated_price' => $item->estimated_price,
                        'product_match' => $item->productMatch === null ? null : [
                            'retail_product_id' => $item->productMatch->retail_product_id,
                            'product_name' => $item->productMatch->retailProduct->name,
                            'external_id' => $item->productMatch->retailProduct->external_id,
                            'product_url' => $item->productMatch->retailProduct->product_url,
                            'pack_count' => $item->productMatch->pack_count,
                            'estimated_total' => $item->productMatch->estimated_total,
                        ],
                        'source_planned_meal_ids' => $item->sources->pluck('planned_meal_id')->filter()->unique()->values()->all(),
                    ])->all(),
                ],
            ]);
        });
    }
}
