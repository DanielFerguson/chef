<?php

namespace App\Policies;

use App\Models\RecipeVersion;
use App\Models\User;

class RecipeVersionPolicy
{
    public function view(User $user, RecipeVersion $recipeVersion): bool
    {
        return $user->memberships()->where('team_id', $recipeVersion->team_id)->exists();
    }
}
