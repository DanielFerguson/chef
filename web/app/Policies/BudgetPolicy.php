<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy
{
    public function view(User $user, Budget $budget): bool
    {
        return $user->memberships()->where('team_id', $budget->team_id)->exists();
    }

    public function update(User $user, Budget $budget): bool
    {
        return $this->view($user, $budget);
    }
}
