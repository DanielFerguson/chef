<?php

namespace App\Actions\MealPlans;

use App\Actions\Planning\AssessMealPlanReadiness;
use App\Enums\MealPlanMilestoneKind;
use App\Models\MealPlan;
use App\Models\MealPlanMilestone;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConfirmMealPlan
{
    public function __construct(
        private readonly AssessMealPlanReadiness $assessReadiness,
        private readonly RecordMealPlanMilestone $recordMilestone,
        private readonly MealPlanSafetyContext $safetyContext,
    ) {}

    public function handle(MealPlan $mealPlan, User $user): MealPlanMilestone
    {
        if ($mealPlan->planning_confirmed_at !== null
            && $mealPlan->confirmed_safety_context_hash === $this->safetyContext->fingerprint($mealPlan)) {
            return $mealPlan->milestones()->where('kind', MealPlanMilestoneKind::PlanningConfirmed)->firstOrFail();
        }

        $readiness = $this->assessReadiness->handle($mealPlan);

        if (! $readiness['ready_for_confirmation'] && ! $readiness['ready_for_safety_confirmation']) {
            throw ValidationException::withMessages([
                'plan' => 'Fill every meal slot, resolve pending suggestions, confirm participants, and review the current safety details before approving the plan.',
            ]);
        }

        return $this->recordMilestone->handle($mealPlan, $user, MealPlanMilestoneKind::PlanningConfirmed);
    }
}
