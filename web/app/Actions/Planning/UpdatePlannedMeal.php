<?php

namespace App\Actions\Planning;

use App\Actions\MealPlans\RecordMealPlanRevision;
use App\Enums\PlannedMealStatus;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePlannedMeal
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(PlannedMeal $plannedMeal, User $user, float $servings, PlannedMealStatus $status, ?string $notes, ?int $expectedRevision = null): PlannedMeal
    {
        if (! $user->memberships()->where('team_id', $plannedMeal->team_id)->exists()) {
            throw new AuthorizationException('You cannot update this meal.');
        }

        if ($servings <= 0) {
            throw ValidationException::withMessages(['servings' => 'Servings must be above zero.']);
        }

        DB::transaction(function () use ($plannedMeal, $user, $servings, $status, $notes, $expectedRevision): void {
            $plannedMeal->update(['servings' => $servings, 'status' => $status, 'notes' => $notes]);
            $this->recordRevision->handle($plannedMeal->mealPlan, $user, 'Updated '.$plannedMeal->title.'.', [
                'planned_meal_id' => $plannedMeal->id,
                'servings' => $servings,
                'status' => $status->value,
            ], $expectedRevision);
        });

        return $plannedMeal;
    }
}
