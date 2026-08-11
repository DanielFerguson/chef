<?php

namespace App\Policies;

use App\Models\BasketRun;
use App\Models\User;

class BasketRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->memberships()->where('team_id', $user->current_team_id)->exists();
    }

    public function view(User $user, BasketRun $basketRun): bool
    {
        return $user->memberships()->where('team_id', $basketRun->team_id)->exists();
    }

    public function update(User $user, BasketRun $basketRun): bool
    {
        return $this->controlsRetailerAccount($user, $basketRun);
    }

    public function retry(User $user, BasketRun $basketRun): bool
    {
        return $this->controlsRetailerAccount($user, $basketRun);
    }

    public function restore(User $user, BasketRun $basketRun): bool
    {
        return $this->controlsRetailerAccount($user, $basketRun);
    }

    public function reviewInRetailer(User $user, BasketRun $basketRun): bool
    {
        return $this->controlsRetailerAccount($user, $basketRun);
    }

    private function controlsRetailerAccount(User $user, BasketRun $basketRun): bool
    {
        $basketRun->loadMissing('connection');
        $ownerUserId = $basketRun->retailer_connection_id === null
            ? $basketRun->requested_by_user_id
            : $basketRun->connection->owner_user_id;

        return $this->view($user, $basketRun)
            && $ownerUserId === $user->id;
    }
}
