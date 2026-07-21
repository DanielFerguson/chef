<?php

namespace App\Actions\Households;

use App\Models\Preference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RemovePreference
{
    public function handle(Preference $preference, User $user): void
    {
        if (! $user->can('delete', $preference)) {
            throw new AuthorizationException('You cannot delete that preference.');
        }

        $preference->delete();
    }
}
