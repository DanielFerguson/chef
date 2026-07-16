<?php

namespace App\Actions\Households;

use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Message;
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
        ?Message $sourceMessage = null,
    ): Preference {
        if (! $user->memberships()->whereBelongsTo($team)->exists() || ($person !== null && $person->team_id !== $team->id)) {
            throw new AuthorizationException('You cannot record a preference for this family.');
        }

        if ($sourceMessage !== null && (
            $sourceMessage->team_id !== $team->id
            || $sourceMessage->user_id !== $user->id
        )) {
            throw new AuthorizationException('That message cannot support a preference for this family.');
        }

        $identity = ['team_id' => $team->id, 'person_id' => $person?->id, 'subject' => $subject];
        $values = [
            ...compact('sentiment', 'provenance', 'strength', 'confidence'),
            'evidence' => $sourceMessage === null ? null : ['message_id' => $sourceMessage->id],
        ];
        $existing = Preference::query()->where($identity)->first();

        if ($existing !== null) {
            $existing->update($values);

            return $existing;
        }

        $personId = $person === null ? '' : (string) $person->id;
        $idempotencyKey = $sourceMessage === null ? null : hash('sha256', implode('|', [
            'preference', $team->id, $sourceMessage->id, $personId, mb_strtolower(trim($subject)),
        ]));

        if ($sourceMessage === null) {
            return Preference::query()->updateOrCreate($identity, $values);
        }

        return Preference::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [...$identity, ...$values],
        );
    }
}
