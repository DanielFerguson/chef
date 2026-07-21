<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMealPlanDateSpan
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(MealPlan $mealPlan, User $user, CarbonInterface $startsOn, CarbonInterface $endsOn): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        if ($endsOn->isBefore($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The plan end date must be on or after its start date.']);
        }

        if ($mealPlan->starts_on->isSameDay($startsOn) && $mealPlan->ends_on->isSameDay($endsOn)) {
            return $mealPlan->refresh();
        }

        if ($mealPlan->slots()->where(function ($query) use ($startsOn, $endsOn): void {
            $query->whereDate('date', '<', $startsOn)->orWhereDate('date', '>', $endsOn);
        })->exists()) {
            throw ValidationException::withMessages(['date_span' => 'Move or remove meals outside the new date span first.']);
        }

        DB::transaction(function () use ($mealPlan, $user, $startsOn, $endsOn): void {
            $generatedTitle = MealPlanDateTitle::format($mealPlan->starts_on, $mealPlan->ends_on);
            $updates = ['starts_on' => $startsOn, 'ends_on' => $endsOn];

            if ($mealPlan->title === $generatedTitle) {
                $updates['title'] = MealPlanDateTitle::format($startsOn, $endsOn);
            }

            $mealPlan->update($updates);

            if (isset($updates['title'])) {
                $mealPlan->conversations()->update(['title' => $updates['title']]);
            }

            $this->recordRevision->handle($mealPlan, $user, 'Changed the plan date range.', [
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
            ]);
        });

        return $mealPlan;
    }
}
