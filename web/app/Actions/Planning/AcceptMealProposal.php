<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Enums\MealProposalStatus;
use App\Models\MealProposal;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptMealProposal
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(MealProposal $proposal, User $user): PlannedMeal
    {
        if (! $user->memberships()->where('team_id', $proposal->team_id)->exists()) {
            throw new AuthorizationException('You cannot update this meal proposal.');
        }

        if ($proposal->meal_slot_id === null) {
            throw ValidationException::withMessages(['meal_slot_id' => 'Choose a meal slot before accepting this proposal.']);
        }

        return DB::transaction(function () use ($proposal, $user): PlannedMeal {
            $proposal = MealProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($proposal->status !== MealProposalStatus::Pending) {
                throw ValidationException::withMessages([
                    'proposal' => 'Only a pending meal proposal can be accepted.',
                ]);
            }

            $existing = PlannedMeal::query()
                ->where('meal_slot_id', $proposal->meal_slot_id)
                ->lockForUpdate()
                ->first();

            if ($existing?->meal_proposal_id !== null) {
                MealProposal::query()
                    ->whereKey($existing->meal_proposal_id)
                    ->where('status', MealProposalStatus::Accepted->value)
                    ->update([
                        'status' => MealProposalStatus::Replaced,
                        'decided_by_user_id' => $user->id,
                        'decided_at' => now(),
                    ]);
            }

            $existing?->delete();

            $plannedMeal = PlannedMeal::query()->create([
                'team_id' => $proposal->team_id,
                'meal_plan_id' => $proposal->meal_plan_id,
                'meal_slot_id' => $proposal->meal_slot_id,
                'meal_proposal_id' => $proposal->id,
                'selected_by_user_id' => $user->id,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'estimated_minutes' => $proposal->estimated_minutes,
                'estimated_cost' => $proposal->estimated_cost,
            ]);

            $proposal->update([
                'status' => MealProposalStatus::Accepted,
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
            ]);

            $this->recordRevision->handle($proposal->mealPlan, $user, 'Selected '.$proposal->title.'.', [
                'meal_slot_id' => $proposal->meal_slot_id,
                'planned_meal_id' => $plannedMeal->id,
                'meal_proposal_id' => $proposal->id,
            ]);

            return $plannedMeal;
        });
    }
}
