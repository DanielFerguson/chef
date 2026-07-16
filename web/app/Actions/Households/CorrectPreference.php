<?php

namespace App\Actions\Households;

use App\Models\Message;
use App\Models\Person;
use App\Models\Preference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectPreference
{
    public function __construct(
        private readonly RecordPreference $recordPreference,
        private readonly ValidatePreferenceEvidence $validateEvidence,
    ) {}

    public function handle(Preference $incorrect, User $user, ?Person $correctedPerson, Message $correctionMessage, string $evidenceQuote, ?string $personReference = null): Preference
    {
        $team = $incorrect->team;

        if (! $user->memberships()->whereBelongsTo($team)->exists()
            || ($correctedPerson !== null && $correctedPerson->team_id !== $team->id)
            || $correctionMessage->team_id !== $team->id
            || $correctionMessage->user_id !== $user->id) {
            throw new AuthorizationException('You cannot correct that preference.');
        }

        if ($incorrect->superseded_at !== null) {
            throw ValidationException::withMessages(['preference_id' => 'That preference has already been corrected.']);
        }

        if ($incorrect->person_id === $correctedPerson?->id) {
            throw ValidationException::withMessages(['corrected_person_id' => 'Choose a different owner for the corrected preference.']);
        }

        $this->validateEvidence->handle($correctionMessage, $team, $incorrect->subject, $evidenceQuote, $correctedPerson, $personReference);

        return DB::transaction(function () use ($incorrect, $user, $correctedPerson, $correctionMessage): Preference {
            $incorrect = Preference::query()->lockForUpdate()->findOrFail($incorrect->id);

            if ($incorrect->superseded_at !== null) {
                throw ValidationException::withMessages(['preference_id' => 'That preference has already been corrected.']);
            }

            $sourceMessage = $incorrect->sourceMessage;
            $corrected = $this->recordPreference->handle(
                team: $incorrect->team,
                user: $user,
                subject: $incorrect->subject,
                sentiment: $incorrect->sentiment,
                provenance: $incorrect->provenance,
                person: $correctedPerson,
                strength: $incorrect->strength,
                confidence: $incorrect->confidence,
                sourceMessage: $sourceMessage,
                evidenceQuote: $incorrect->evidence_quote,
            );
            $corrected->update([
                'correction_message_id' => $correctionMessage->id,
                'evidence' => array_filter([
                    'message_id' => $sourceMessage?->id,
                    'correction_message_id' => $correctionMessage->id,
                ]),
            ]);
            $incorrect->update([
                'correction_message_id' => $correctionMessage->id,
                'superseded_by_id' => $corrected->id,
                'superseded_at' => now(),
            ]);

            return $corrected->fresh(['person', 'sourceMessage', 'correctionMessage']);
        });
    }
}
