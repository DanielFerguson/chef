<?php

namespace App\Actions\Retailers;

use App\Models\RetailerProductPreference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RevokeRetailerProductPreference
{
    public function handle(RetailerProductPreference $preference, User $user): RetailerProductPreference
    {
        if (! $user->can('update', $preference)) {
            throw new AuthorizationException('You cannot revoke this grocery preference.');
        }

        if ($preference->revoked_at === null) {
            $preference->update(['revoked_at' => now()]);
        }

        return $preference->refresh();
    }
}
