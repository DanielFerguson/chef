<?php

namespace App\Actions\Cooking;

use App\Enums\MealFeedbackRating;
use App\Enums\PreferenceCandidateStatus;
use App\Enums\PreferenceSentiment;
use App\Models\MealFeedback;
use App\Models\Person;
use App\Models\PreferenceCandidate;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class DerivePreferenceCandidates
{
    public function handle(Person $person, ?Recipe $recipe, string $mealTitle): ?PreferenceCandidate
    {
        $normalizedSubject = mb_strtolower(trim($mealTitle));
        $feedback = MealFeedback::query()
            ->where('team_id', $person->team_id)
            ->where('person_id', $person->id)
            ->whereHas('outcome.plannedMeal', function ($query) use ($recipe, $normalizedSubject): void {
                if ($recipe !== null) {
                    $query->whereHas('recipeVersion', fn ($versionQuery) => $versionQuery->where('recipe_id', $recipe->id));

                    return;
                }

                $query->whereRaw('lower(title) = ?', [$normalizedSubject]);
            })
            ->orderBy('id')
            ->get();

        $positive = $feedback->filter(fn (MealFeedback $item) => in_array($item->rating, [MealFeedbackRating::Like, MealFeedbackRating::Favourite], true));
        $negative = $feedback->filter(fn (MealFeedback $item) => $item->rating === MealFeedbackRating::Dislike);
        $candidateQuery = PreferenceCandidate::query()
            ->where('team_id', $person->team_id)
            ->where('person_id', $person->id)
            ->where('status', PreferenceCandidateStatus::Pending)
            ->when(
                $recipe !== null,
                fn ($query) => $query->where('recipe_id', $recipe->id),
                fn ($query) => $query->whereNull('recipe_id')->where('normalized_subject', $normalizedSubject),
            );

        if (max($positive->count(), $negative->count()) < 2 || $positive->count() === $negative->count()) {
            $candidateQuery->delete();

            return null;
        }

        $evidence = $positive->count() > $negative->count() ? $positive : $negative;
        $sentiment = $positive->count() > $negative->count() ? PreferenceSentiment::Like : PreferenceSentiment::Dislike;
        $identity = $recipe === null ? 'title:'.$normalizedSubject : 'recipe:'.$recipe->id;
        $identityKey = hash('sha256', implode('|', ['preference-candidate', $person->team_id, $person->id, $identity, $sentiment->value]));
        $confidence = min(0.95, 0.5 + ($evidence->count() * 0.15));

        $candidateQuery->where('sentiment', '!=', $sentiment)->delete();

        return DB::transaction(function () use ($person, $recipe, $mealTitle, $normalizedSubject, $sentiment, $identityKey, $confidence, $evidence): PreferenceCandidate {
            $candidate = PreferenceCandidate::query()->where('identity_key', $identityKey)->lockForUpdate()->first();

            if ($candidate !== null && $candidate->status !== PreferenceCandidateStatus::Pending) {
                return $candidate;
            }

            return PreferenceCandidate::query()->updateOrCreate(
                ['identity_key' => $identityKey],
                [
                    'team_id' => $person->team_id,
                    'person_id' => $person->id,
                    'recipe_id' => $recipe?->id,
                    'subject' => trim($mealTitle),
                    'normalized_subject' => $normalizedSubject,
                    'sentiment' => $sentiment,
                    'evidence_count' => $evidence->count(),
                    'confidence' => $confidence,
                    'evidence' => ['meal_feedback_ids' => $evidence->pluck('id')->all()],
                    'status' => PreferenceCandidateStatus::Pending,
                ],
            );
        });
    }
}
