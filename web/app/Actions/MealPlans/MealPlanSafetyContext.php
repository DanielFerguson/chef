<?php

namespace App\Actions\MealPlans;

use App\Models\Constraint;
use App\Models\MealPlan;
use App\Models\PlannedMeal;

class MealPlanSafetyContext
{
    public function fingerprint(MealPlan $mealPlan): string
    {
        $teamConstraints = $mealPlan->team
            ->constraints()
            ->whereNull('person_id')
            ->get()
            ->sortBy('id')
            ->map(fn (Constraint $constraint): array => $this->constraintPayload($constraint))
            ->values()
            ->all();
        $meals = $mealPlan->plannedMeals()
            ->where('status', 'planned')
            ->with('mealSlot.participants.constraints')
            ->get()
            ->sortBy(fn (PlannedMeal $meal): string => implode('|', [
                $meal->mealSlot->date->toDateString(),
                $meal->mealSlot->position,
                $meal->id,
            ]))
            ->map(fn (PlannedMeal $meal): array => [
                'planned_meal_id' => $meal->id,
                'title' => $meal->title,
                'type' => $meal->type->value,
                'participants' => $meal->mealSlot->participants
                    ->sortBy('id')
                    ->map(fn ($person): array => [
                        'person_id' => $person->id,
                        'servings' => (float) $person->getRelation('pivot')->getAttribute('servings'),
                        'constraints' => $person->constraints
                            ->sortBy('id')
                            ->map(fn (Constraint $constraint): array => $this->constraintPayload($constraint))
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'team_constraints' => $teamConstraints,
            'meals' => $meals,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function constraintPayload(Constraint $constraint): array
    {
        return [
            'id' => $constraint->id,
            'kind' => $constraint->kind->value,
            'subject' => $constraint->subject,
            'details' => $constraint->details,
            'severity' => $constraint->severity,
            'explicitly_confirmed_at' => $constraint->explicitly_confirmed_at->toISOString(),
        ];
    }
}
