<?php

namespace App\Actions\Households;

use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Person;
use App\Models\Preference;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RecordPreference
{
    public function handle(
        Team $team,
        User $user,
        string $subject,
        PreferenceSentiment $sentiment,
        PreferenceProvenance $provenance,
        ?Person $person = null,
        int $strength = 3,
        ?float $confidence = null,
    ): Preference {
        if (! $user->memberships()->whereBelongsTo($team)->exists() || ($person !== null && $person->team_id !== $team->id)) {
            throw new AuthorizationException('You cannot record a preference for this family.');
        }

        return Preference::query()->updateOrCreate(
            ['team_id' => $team->id, 'person_id' => $person?->id, 'subject' => $subject],
            compact('sentiment', 'provenance', 'strength', 'confidence'),
        );
    }
}
