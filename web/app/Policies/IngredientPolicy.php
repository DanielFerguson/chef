<?php

namespace App\Policies;

use App\Models\Ingredient;
use App\Models\User;

class IngredientPolicy
{
    public function view(User $user, Ingredient $ingredient): bool
    {
        return $user->memberships()->where('team_id', $ingredient->team_id)->exists();
    }
}
