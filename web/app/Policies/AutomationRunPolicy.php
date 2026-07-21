<?php

namespace App\Policies;

use App\Models\AutomationRun;
use App\Models\User;

class AutomationRunPolicy
{
    public function view(User $user, AutomationRun $run): bool
    {
        return $user->memberships()->where('team_id', $run->team_id)->exists();
    }

    public function update(User $user, AutomationRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function cancel(User $user, AutomationRun $run): bool
    {
        return $this->view($user, $run);
    }
}
