<?php

namespace App\Policies;

use App\Models\AutomationStep;
use App\Models\User;

class AutomationStepPolicy
{
    public function view(User $user, AutomationStep $step): bool
    {
        return $user->memberships()->where('team_id', $step->team_id)->exists();
    }
}
