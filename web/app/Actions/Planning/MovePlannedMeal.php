<?php

namespace App\Actions\Planning;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class MovePlannedMeal
{
    public function handle(PlannedMeal $plannedMeal, MealSlot $target, User $user): PlannedMeal
    {
        if (! $user->memberships()->where('team_id', $plannedMeal->team_id)->exists()
            || $target->team_id !== $plannedMeal->team_id
            || $target->meal_plan_id !== $plannedMeal->meal_plan_id) {
            throw new AuthorizationException('You cannot move this meal to that slot.');
        }

        if ($target->plannedMeal()->exists()) {
            if ($plannedMeal->meal_slot_id === $target->id) {
                return $plannedMeal;
            }

            throw ValidationException::withMessages(['meal_slot_id' => 'Replace the existing meal before moving into this slot.']);
        }

        $plannedMeal->update(['meal_slot_id' => $target->id]);

        return $plannedMeal;
    }
}
