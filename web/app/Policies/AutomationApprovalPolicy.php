<?php

namespace App\Policies;

use App\Models\AutomationApproval;
use App\Models\User;

class AutomationApprovalPolicy
{
    public function view(User $user, AutomationApproval $approval): bool
    {
        return $user->memberships()->where('team_id', $approval->team_id)->exists();
    }

    public function update(User $user, AutomationApproval $approval): bool
    {
        return $this->view($user, $approval);
    }
}
