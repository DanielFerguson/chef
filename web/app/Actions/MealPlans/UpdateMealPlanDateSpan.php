<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateMealPlanDateSpan
{
    public function handle(MealPlan $mealPlan, User $user, CarbonInterface $startsOn, CarbonInterface $endsOn): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        if ($endsOn->isBefore($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The plan end date must be on or after its start date.']);
        }

        if ($mealPlan->slots()->where(function ($query) use ($startsOn, $endsOn): void {
            $query->whereDate('date', '<', $startsOn)->orWhereDate('date', '>', $endsOn);
        })->exists()) {
            throw ValidationException::withMessages(['date_span' => 'Move or remove meals outside the new date span first.']);
        }

        $mealPlan->update(['starts_on' => $startsOn, 'ends_on' => $endsOn]);

        return $mealPlan;
    }
}
