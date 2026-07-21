<?php

namespace App\Actions\MealPlans;

use App\Models\Person;
use App\Models\Team;
use App\Models\User;

class InvalidateMealPlansForConstraintChange
{
    public function __construct(private readonly RecordMealPlanRevision $recordRevision) {}

    public function handle(Team $team, User $user, ?Person $person, string $summary): void
    {
        $plans = $team->mealPlans()
            ->whereNotNull('planning_confirmed_at')
            ->whereDate('ends_on', '>=', today())
            ->whereHas('plannedMeals', fn ($query) => $query
                ->where('status', 'planned')
                ->whereNotNull('recipe_version_id'))
            ->when($person !== null, fn ($query) => $query->whereHas(
                'slots.participants',
                fn ($participants) => $participants->whereKey($person->id),
            ))
            ->get();

        foreach ($plans as $plan) {
            $this->recordRevision->handle($plan, $user, $summary, [
                'safety_context_changed' => true,
                'person_id' => $person?->id,
            ]);
        }
    }
}
