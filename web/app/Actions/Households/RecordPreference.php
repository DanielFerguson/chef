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
use Illuminate\Support\Facades\DB;

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
        ?string $evidenceQuote = null,
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

        $subject = trim($subject);
        $values = [
            ...compact('sentiment', 'provenance', 'strength', 'confidence'),
            'evidence' => $sourceMessage === null ? null : ['message_id' => $sourceMessage->id],
            'source_message_id' => $sourceMessage?->id,
            'evidence_quote' => $evidenceQuote,
        ];

        return DB::transaction(function () use ($team, $person, $subject, $values, $sourceMessage): Preference {
            $existing = Preference::query()
                ->active()
                ->where('team_id', $team->id)
                ->where('person_id', $person?->id)
                ->whereRaw('lower(subject) = ?', [mb_strtolower($subject)])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->update([...$values, 'subject' => $subject]);

                return $existing;
            }

            $personId = $person === null ? '' : (string) $person->id;
            $idempotencyKey = $sourceMessage === null ? null : hash('sha256', implode('|', [
                'preference', $team->id, $sourceMessage->id, $personId, mb_strtolower($subject),
            ]));
            $attributes = ['team_id' => $team->id, 'person_id' => $person?->id, 'subject' => $subject];

            if ($sourceMessage === null) {
                return Preference::query()->create([...$attributes, ...$values]);
            }

            return Preference::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [...$attributes, ...$values],
            );
        });
    }
}
