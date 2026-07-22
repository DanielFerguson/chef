<?php

namespace App\Actions\Shopping;

use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SetShoppingFulfilment
{
    public function handle(ShoppingList $shoppingList, User $user, string $method): ShoppingList
    {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot update fulfilment for this shopping list.');
        }

        if (! in_array($method, ['delivery', 'pickup'], true)) {
            throw ValidationException::withMessages([
                'fulfilment_method' => 'Choose delivery or pickup.',
            ]);
        }

        $shoppingList->update([
            'fulfilment_method' => $method,
            'fulfilment_scheduled_for' => null,
            'fulfilment_confirmed_at' => null,
        ]);

        return $shoppingList->refresh();
    }
}
