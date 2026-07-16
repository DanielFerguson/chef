<?php

namespace App\Policies;

use App\Models\MealProposal;
use App\Models\User;

class MealProposalPolicy
{
    public function update(User $user, MealProposal $proposal): bool
    {
        return $user->memberships()->where('team_id', $proposal->team_id)->exists();
    }
}
