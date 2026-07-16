<?php

namespace App\Actions\Shopping;

use App\Enums\ShoppingListStatus;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DeleteShoppingListItem
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    public function handle(ShoppingListItem $item, User $user, int $expectedRevision): void
    {
        if (! $user->can('delete', $item)) {
            throw new AuthorizationException('You cannot delete this shopping-list item.');
        }

        DB::transaction(function () use ($item, $user, $expectedRevision): void {
            $shoppingList = $this->ensureEditable->handle($item->shoppingList);
            $name = $item->name;
            $item->delete();
            $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
            $this->recordRevision->handle($shoppingList, $user, 'Removed '.$name, $expectedRevision);
        });
    }
}
