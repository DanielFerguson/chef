<?php

namespace App\Actions\Planning;

use App\Enums\MealProposalStatus;
use App\Models\MealProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectMealProposal
{
    public function handle(MealProposal $proposal, User $user): MealProposal
    {
        if (! $user->memberships()->where('team_id', $proposal->team_id)->exists()) {
            throw new AuthorizationException('You cannot update this meal proposal.');
        }

        return DB::transaction(function () use ($proposal, $user): MealProposal {
            $proposal = MealProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($proposal->status !== MealProposalStatus::Pending) {
                throw ValidationException::withMessages([
                    'proposal' => 'Only a pending meal proposal can be rejected.',
                ]);
            }

            $proposal->update([
                'status' => MealProposalStatus::Rejected,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
            ]);

            return $proposal;
        });
    }
}
