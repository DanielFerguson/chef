<?php

namespace App\Actions\Shopping;

use App\Enums\CartLineClassification;
use App\Enums\ShoppingListStatus;
use App\Models\CartSnapshot;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReconcileOrderedShoppingItems
{
    public function __construct(private readonly CompleteShoppingList $completeShoppingList) {}

    public function handle(CartSnapshot $snapshot, User $user): ShoppingList
    {
        $snapshot->loadMissing('run.shoppingList', 'lines');
        $shoppingList = $snapshot->run->shoppingList;
        $orderedItemIds = $snapshot->lines
            ->reject(fn ($line) => $line->pre_existing)
            ->reject(fn ($line) => in_array($line->classification, [
                CartLineClassification::Unavailable,
                CartLineClassification::Unresolved,
            ], true))
            ->pluck('shopping_list_item_id')
            ->filter()
            ->unique()
            ->values();

        DB::transaction(function () use ($shoppingList, $snapshot, $orderedItemIds): void {
            $locked = ShoppingList::query()->lockForUpdate()->findOrFail($shoppingList->id);
            $locked->items()
                ->whereIn('id', $orderedItemIds)
                ->update([
                    'ordered_at' => now(),
                    'ordered_via_cart_snapshot_id' => $snapshot->id,
                ]);
        });

        $shoppingList = $shoppingList->refresh();
        $remaining = $shoppingList->items()
            ->where('included', true)
            ->where('in_pantry', false)
            ->where('checked', false)
            ->whereNull('ordered_at')
            ->count();

        if ($remaining === 0 && $shoppingList->status !== ShoppingListStatus::Completed) {
            return $this->completeShoppingList->handle($shoppingList, $user, $shoppingList->revision);
        }

        return $shoppingList;
    }
}
