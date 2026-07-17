<?php

namespace App\Models;

use App\Enums\PreferenceCandidateStatus;
use App\Enums\PreferenceSentiment;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $person_id
 * @property int|null $recipe_id
 * @property string $identity_key
 * @property string $subject
 * @property string $normalized_subject
 * @property PreferenceSentiment $sentiment
 * @property int $evidence_count
 * @property float $confidence
 * @property array<string, mixed> $evidence
 * @property PreferenceCandidateStatus $status
 */
#[Fillable(['team_id', 'person_id', 'recipe_id', 'reviewed_by_user_id', 'preference_id', 'identity_key', 'subject', 'normalized_subject', 'sentiment', 'evidence_count', 'confidence', 'evidence', 'status', 'reviewed_at'])]
class PreferenceCandidate extends Model
{
    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<Preference, $this> */
    public function preference(): BelongsTo
    {
        return $this->belongsTo(Preference::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sentiment' => PreferenceSentiment::class,
            'status' => PreferenceCandidateStatus::class,
            'confidence' => 'float',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
