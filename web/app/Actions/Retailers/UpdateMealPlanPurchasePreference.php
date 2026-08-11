<?php

namespace App\Actions\Retailers;

use App\Enums\RetailerProvider;
use App\Models\MealPlan;
use App\Models\MealPlanPurchasePreference;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateMealPlanPurchasePreference
{
    public function handle(
        MealPlan $mealPlan,
        User $user,
        ?int $basketTargetCents,
        RetailerProvider $provider = RetailerProvider::Coles,
        ?Message $sourceMessage = null,
    ): MealPlanPurchasePreference {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update purchasing preferences for this meal plan.');
        }
        if ($basketTargetCents !== null && $basketTargetCents < 1) {
            throw ValidationException::withMessages([
                'basket_target_cents' => 'The plan basket target must be above zero.',
            ]);
        }
        if ($sourceMessage !== null && (
            $sourceMessage->team_id !== $mealPlan->team_id
            || $sourceMessage->conversation?->meal_plan_id !== $mealPlan->id
        )) {
            throw new AuthorizationException('That preference source does not belong to this meal plan.');
        }

        return MealPlanPurchasePreference::query()->updateOrCreate(
            ['meal_plan_id' => $mealPlan->id, 'provider' => $provider],
            [
                'team_id' => $mealPlan->team_id,
                'updated_by_user_id' => $user->id,
                'source_message_id' => $sourceMessage?->id,
                'basket_target_cents' => $basketTargetCents,
            ],
        );
    }
}
