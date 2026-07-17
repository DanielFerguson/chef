<?php

namespace App\Actions\Planning;

use App\Enums\PreferenceCandidateStatus;
use App\Models\MealPlan;
use App\Models\PlannedMeal;
use App\Models\PreferenceCandidate;
use App\Models\RecipeVersion;

class BuildRecommendationExplanation
{
    /** @return array<string, mixed> */
    public function handle(MealPlan $mealPlan, RecipeVersion $recipeVersion, ?float $estimatedCost = null): array
    {
        $team = $mealPlan->team()->with(['constraints', 'preferences', 'people.constraints', 'people.preferences'])->firstOrFail();
        $ingredientNames = $recipeVersion->ingredients->pluck('name')->map(fn (string $name) => mb_strtolower($name));
        $constraints = $team->constraints->concat($team->people->flatMap->constraints)
            ->filter(fn ($constraint) => $ingredientNames->contains(fn (string $name) => str_contains($name, mb_strtolower($constraint->subject))))
            ->map(fn ($constraint) => $constraint->subject)->unique()->values();
        $preferences = $team->preferences->concat($team->people->flatMap->preferences)
            ->filter(fn ($preference) => $ingredientNames->contains(fn (string $name) => str_contains($name, mb_strtolower($preference->subject))))
            ->map(fn ($preference) => ['subject' => $preference->subject, 'sentiment' => $preference->sentiment->value])
            ->unique(fn (array $item) => $item['subject'].'|'.$item['sentiment'])->values();
        $previous = PlannedMeal::query()
            ->where('team_id', $mealPlan->team_id)
            ->where('recipe_version_id', $recipeVersion->id)
            ->where('meal_plan_id', '!=', $mealPlan->id)
            ->with('mealSlot:id,date')
            ->latest('id')
            ->first();
        $totalMinutes = ($recipeVersion->prep_minutes ?? 0) + ($recipeVersion->cook_minutes ?? 0);
        $candidates = PreferenceCandidate::query()
            ->where('team_id', $mealPlan->team_id)
            ->where('recipe_id', $recipeVersion->recipe_id)
            ->whereIn('status', [PreferenceCandidateStatus::Pending, PreferenceCandidateStatus::Accepted])
            ->with('person:id,name')
            ->get();
        $feedbackSignals = [];

        foreach ($candidates as $candidate) {
            $feedbackSignals[] = [
                'person' => $candidate->person->name,
                'sentiment' => $candidate->sentiment->value,
                'evidence_count' => $candidate->evidence_count,
                'confidence' => $candidate->confidence,
                'status' => $candidate->status->value,
                'explanation' => sprintf(
                    '%s rated this meal %s across %d recorded meals%s.',
                    $candidate->person->name,
                    $candidate->sentiment->value === 'like' ? 'positively' : 'negatively',
                    $candidate->evidence_count,
                    $candidate->status->value === 'pending' ? '; this remains a preference candidate for review' : '',
                ),
            ];
        }

        return [
            'safety' => $constraints->isEmpty()
                ? 'No recorded safety constraint matched this recipe’s ingredients.'
                : 'Review before selecting: '.implode(', ', $constraints->all()).'.',
            'preferences' => $preferences->all(),
            'feedback' => $feedbackSignals,
            'recency' => $previous === null
                ? 'Not found in earlier plans.'
                : 'Last planned on '.$previous->mealSlot->date->toDateString().'.',
            'effort' => $totalMinutes > 0 ? $totalMinutes.' minutes total.' : 'Cooking time is not recorded yet.',
            'cost' => $estimatedCost === null ? 'Cost is not estimated yet.' : sprintf('Estimated at $%.2f.', $estimatedCost),
        ];
    }
}
