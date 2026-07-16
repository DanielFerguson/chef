<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MovePlannedMeal
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(PlannedMeal $plannedMeal, MealSlot $target, User $user, int $expectedRevision): PlannedMeal
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

        DB::transaction(function () use ($plannedMeal, $target, $user, $expectedRevision): void {
            $fromSlotId = $plannedMeal->meal_slot_id;
            $plannedMeal->update(['meal_slot_id' => $target->id]);
            $this->recordRevision->handle($plannedMeal->mealPlan, $user, 'Moved '.$plannedMeal->title.' to '.$target->date->toDateString().'.', [
                'planned_meal_id' => $plannedMeal->id,
                'from_meal_slot_id' => $fromSlotId,
                'to_meal_slot_id' => $target->id,
            ], $expectedRevision);
        });

        return $plannedMeal;
    }
}
