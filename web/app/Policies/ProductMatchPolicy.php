<?php

namespace App\Policies;

use App\Models\ProductMatch;
use App\Models\User;

class ProductMatchPolicy
{
    public function view(User $user, ProductMatch $match): bool
    {
        return $user->memberships()->where('team_id', $match->team_id)->exists();
    }

    public function update(User $user, ProductMatch $match): bool
    {
        return $this->view($user, $match);
    }
}
