<?php

namespace App\Policies;

use App\Models\ProductPreference;
use App\Models\User;

class ProductPreferencePolicy
{
    public function view(User $user, ProductPreference $preference): bool
    {
        return $user->memberships()->where('team_id', $preference->team_id)->exists();
    }

    public function update(User $user, ProductPreference $preference): bool
    {
        return $this->view($user, $preference);
    }
}
