<?php

namespace App\Actions\Shopping;

use App\Models\ShoppingList;
use Illuminate\Validation\ValidationException;

class EnsureShoppingListIsEditable
{
    public function handle(ShoppingList $shoppingList): ShoppingList
    {
        $locked = ShoppingList::query()->lockForUpdate()->findOrFail($shoppingList->id);

        if ($locked->stale_at !== null) {
            throw ValidationException::withMessages([
                'shopping_list' => 'Regenerate this list before changing its items.',
            ]);
        }

        return $locked;
    }
}
