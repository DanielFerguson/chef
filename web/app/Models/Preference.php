<?php

namespace App\Models;

use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\PreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $person_id
 * @property string $subject
 * @property PreferenceSentiment $sentiment
 * @property int $strength
 * @property PreferenceProvenance $provenance
 * @property float|null $confidence
 * @property array<string, mixed>|null $evidence
 */
#[Fillable(['team_id', 'person_id', 'subject', 'sentiment', 'strength', 'provenance', 'confidence', 'evidence'])]
class Preference extends Model
{
    /** @use HasFactory<PreferenceFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sentiment' => PreferenceSentiment::class,
            'provenance' => PreferenceProvenance::class,
            'confidence' => 'float',
            'evidence' => 'array',
        ];
    }
}
