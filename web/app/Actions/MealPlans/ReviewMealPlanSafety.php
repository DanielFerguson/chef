<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ReviewMealPlanSafety
{
    public function __construct(private readonly MealPlanSafetyContext $safetyContext) {}

    public function handle(MealPlan $mealPlan, User $user): MealPlan
    {
        if (! $user->memberships()->where('team_id', $mealPlan->team_id)->exists()) {
            throw new AuthorizationException('You cannot review safety details for this family.');
        }

        $mealPlan->update([
            'safety_reviewed_at' => now(),
            'safety_reviewed_by_user_id' => $user->id,
            'safety_reviewed_context_hash' => $this->safetyContext->fingerprint($mealPlan),
        ]);

        return $mealPlan->refresh();
    }
}
