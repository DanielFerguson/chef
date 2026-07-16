<?php

namespace App\Models;

use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\PreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
#[Fillable(['team_id', 'person_id', 'subject', 'sentiment', 'strength', 'provenance', 'confidence', 'evidence', 'idempotency_key', 'source_message_id', 'evidence_quote', 'correction_message_id', 'superseded_by_id', 'superseded_at'])]
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

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function correctionMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'correction_message_id');
    }

    /** @return BelongsTo<Preference, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<Preference, $this> */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    /** @param Builder<Preference> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sentiment' => PreferenceSentiment::class,
            'provenance' => PreferenceProvenance::class,
            'confidence' => 'float',
            'evidence' => 'array',
            'superseded_at' => 'datetime',
        ];
    }
}
