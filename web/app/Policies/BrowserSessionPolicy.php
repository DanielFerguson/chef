<?php

namespace App\Policies;

use App\Models\BrowserSession;
use App\Models\User;

class BrowserSessionPolicy
{
    public function view(User $user, BrowserSession $session): bool
    {
        return $user->memberships()->where('team_id', $session->team_id)->exists();
    }

    public function control(User $user, BrowserSession $session): bool
    {
        return $this->view($user, $session)
            && $session->retailerConnection()->where('owner_user_id', $user->id)->exists();
    }
}
