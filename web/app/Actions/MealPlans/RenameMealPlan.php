<?php

namespace App\Actions\MealPlans;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RenameMealPlan
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(MealPlan $mealPlan, User $user, string $title, ?int $expectedRevision = null): MealPlan
    {
        if (! $user->can('update', $mealPlan)) {
            throw new AuthorizationException('You cannot update this meal plan.');
        }

        $title = trim($title);

        if ($title === '' || Str::length($title) > 120) {
            throw ValidationException::withMessages([
                'title' => 'The plan name must be between 1 and 120 characters.',
            ]);
        }

        if ($mealPlan->title === $title) {
            return $mealPlan;
        }

        DB::transaction(function () use ($mealPlan, $user, $title, $expectedRevision): void {
            $mealPlan->update(['title' => $title]);
            $mealPlan->conversations()->update(['title' => $title]);
            $this->recordRevision->handle(
                $mealPlan,
                $user,
                'Renamed the plan.',
                ['title' => $title],
                $expectedRevision,
            );
        });

        return $mealPlan->refresh();
    }
}
