<?php

namespace App\Ai\Tools;

use App\Actions\Households\RecordPreference;
use App\Actions\Households\ValidatePreferenceEvidence;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Message;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecordHouseholdPreference implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly RecordPreference $recordPreference,
        private readonly ValidatePreferenceEvidence $validateEvidence,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a food like or dislike stated by a person, or a clearly labelled low-stakes inference. Never use this for allergies or medical restrictions.';
    }

    public function handle(Request $request): Stringable|string
    {
        $scope = $request->string('scope')->toString();
        $personId = $request->integer('person_id');
        $person = $personId > 0
            ? Person::query()->where('team_id', $this->team->id)->findOrFail($personId)
            : null;
        $provenance = PreferenceProvenance::from($request->string('provenance')->toString());
        $evidenceQuote = $request->string('evidence_quote')->toString();
        $personReference = $request->string('person_reference')->toString();

        if (($scope === 'person' && $person === null) || ($scope === 'family' && $person !== null)) {
            throw ValidationException::withMessages(['scope' => 'Choose either one identified person or the whole family.']);
        }

        if ($provenance === PreferenceProvenance::Stated) {
            $this->validateEvidence->handle(
                $this->sourceMessage,
                $this->team,
                $request->string('subject')->toString(),
                $evidenceQuote,
                $person,
                $personReference ?: null,
            );
        }

        $preference = $this->recordPreference->handle(
            team: $this->team,
            user: $this->actor,
            subject: $request->string('subject')->toString(),
            sentiment: PreferenceSentiment::from($request->string('sentiment')->toString()),
            provenance: $provenance,
            person: $person,
            strength: max(1, min(5, $request->integer('strength', 3))),
            confidence: $request->float('confidence') ?: null,
            sourceMessage: $this->sourceMessage,
            evidenceQuote: $evidenceQuote ?: null,
        );

        return $preference->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->description('Either person or family. Never guess a person.')->required(),
            'person_id' => $schema->integer()->description('Exact person identifier from InspectTeamContext when scope is person.'),
            'person_reference' => $schema->string()->description('Exact name or pronoun in the evidence quote that identifies the person.'),
            'subject' => $schema->string()->description('Food, cuisine, ingredient, or preparation preference.')->required(),
            'sentiment' => $schema->string()->description('Either like or dislike.')->required(),
            'provenance' => $schema->string()->description('stated, default, or inferred.')->required(),
            'strength' => $schema->integer()->description('Preference strength from 1 to 5.'),
            'confidence' => $schema->number()->description('Confidence from 0 to 1 for inferred preferences.'),
            'evidence_quote' => $schema->string()->description('Exact words from the current user message supporting a stated preference.'),
        ];
    }
}
