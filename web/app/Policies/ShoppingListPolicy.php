<?php

namespace App\Policies;

use App\Models\ShoppingList;
use App\Models\User;

class ShoppingListPolicy
{
    public function view(User $user, ShoppingList $shoppingList): bool
    {
        return $user->memberships()->where('team_id', $shoppingList->team_id)->exists();
    }

    public function update(User $user, ShoppingList $shoppingList): bool
    {
        return $this->view($user, $shoppingList);
    }
}
