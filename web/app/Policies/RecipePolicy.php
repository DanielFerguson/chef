<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\Team;
use App\Models\User;

class RecipePolicy
{
    public function view(User $user, Recipe $recipe): bool
    {
        return $user->memberships()->where('team_id', $recipe->team_id)->exists();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->memberships()->whereBelongsTo($team)->exists();
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe);
    }
}
