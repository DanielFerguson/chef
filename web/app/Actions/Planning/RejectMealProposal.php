<?php

namespace App\Actions\Planning;

use App\Enums\MealProposalStatus;
use App\Models\MealProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RejectMealProposal
{
    public function handle(MealProposal $proposal, User $user): MealProposal
    {
        if (! $user->memberships()->where('team_id', $proposal->team_id)->exists()) {
            throw new AuthorizationException('You cannot update this meal proposal.');
        }

        $proposal->update([
            'status' => MealProposalStatus::Rejected,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
        ]);

        return $proposal;
    }
}
