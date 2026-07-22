<?php

namespace App\Policies;

use App\Models\RetailerOrderRun;
use App\Models\User;

class RetailerOrderRunPolicy
{
    public function view(User $user, RetailerOrderRun $run): bool
    {
        return $user->memberships()->where('team_id', $run->team_id)->exists();
    }

    public function update(User $user, RetailerOrderRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function selectFulfilment(User $user, RetailerOrderRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function confirm(User $user, RetailerOrderRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function verifyPlacement(User $user, RetailerOrderRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function cancel(User $user, RetailerOrderRun $run): bool
    {
        return $this->view($user, $run);
    }
}
