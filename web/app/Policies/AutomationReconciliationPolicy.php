<?php

namespace App\Policies;

use App\Models\AutomationReconciliation;
use App\Models\User;

class AutomationReconciliationPolicy
{
    public function view(User $user, AutomationReconciliation $reconciliation): bool
    {
        return $user->memberships()->where('team_id', $reconciliation->team_id)->exists();
    }
}
