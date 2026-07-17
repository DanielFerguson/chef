<?php

namespace App\Models;

use App\Enums\MealFeedbackRating;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $meal_outcome_id
 * @property int $person_id
 * @property MealFeedbackRating $rating
 */
#[Fillable(['team_id', 'meal_outcome_id', 'person_id', 'recorded_by_user_id', 'rating', 'portion', 'effort', 'cost', 'leftovers', 'notes', 'recipe_adjustment', 'submitted_at'])]
class MealFeedback extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<MealOutcome, $this> */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(MealOutcome::class, 'meal_outcome_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rating' => MealFeedbackRating::class, 'submitted_at' => 'datetime'];
    }
}
