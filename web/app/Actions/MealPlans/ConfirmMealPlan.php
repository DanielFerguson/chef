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
        private readonly ReviewMealPlanSafety $reviewSafety,
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
                'plan' => 'Fill every meal slot, resolve pending suggestions, and confirm participants before approving the plan.',
            ]);
        }

        $this->reviewSafety->handle($mealPlan, $user);

        return $this->recordMilestone->handle($mealPlan->refresh(), $user, MealPlanMilestoneKind::PlanningConfirmed);
    }
}
