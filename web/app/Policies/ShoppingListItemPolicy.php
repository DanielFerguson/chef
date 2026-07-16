<?php

namespace App\Policies;

use App\Models\ShoppingListItem;
use App\Models\User;

class ShoppingListItemPolicy
{
    public function update(User $user, ShoppingListItem $item): bool
    {
        return $user->memberships()->where('team_id', $item->team_id)->exists();
    }

    public function delete(User $user, ShoppingListItem $item): bool
    {
        return $this->update($user, $item);
    }
}
