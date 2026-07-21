<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

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

        if ($plannedMeal->meal_slot_id === $target->id) {
            return $plannedMeal;
        }

        DB::transaction(function () use ($plannedMeal, $target, $user, $expectedRevision): void {
            $fromSlotId = $plannedMeal->meal_slot_id;
            $fromSlot = MealSlot::query()->findOrFail($fromSlotId);
            $targetMeal = PlannedMeal::query()->where('meal_slot_id', $target->id)->first();

            if ($targetMeal !== null) {
                $temporarySlot = MealSlot::query()->create([
                    'team_id' => $plannedMeal->team_id,
                    'meal_plan_id' => $plannedMeal->meal_plan_id,
                    'date' => $fromSlot->date,
                    'kind' => $fromSlot->kind,
                    'label' => $fromSlot->label,
                    'position' => ((int) MealSlot::query()
                        ->where('meal_plan_id', $plannedMeal->meal_plan_id)
                        ->whereDate('date', $fromSlot->date)
                        ->where('kind', $fromSlot->kind)
                        ->max('position')) + 1,
                ]);
                $targetMeal->update(['meal_slot_id' => $temporarySlot->id]);
                $plannedMeal->update(['meal_slot_id' => $target->id]);
                $targetMeal->update(['meal_slot_id' => $fromSlotId]);
                $temporarySlot->delete();
            } else {
                $plannedMeal->update(['meal_slot_id' => $target->id]);
            }

            $summary = $targetMeal === null
                ? 'Moved '.$plannedMeal->title.' to '.$target->date->toDateString().'.'
                : 'Swapped '.$plannedMeal->title.' with '.$targetMeal->title.'.';
            $this->recordRevision->handle($plannedMeal->mealPlan, $user, $summary, [
                'planned_meal_id' => $plannedMeal->id,
                'swapped_planned_meal_id' => $targetMeal?->id,
                'from_meal_slot_id' => $fromSlotId,
                'to_meal_slot_id' => $target->id,
            ], $expectedRevision);
        });

        return $plannedMeal;
    }
}
