<?php

namespace App\Ai\Tools;

use App\Actions\Households\RecordPreference;
use App\Enums\PreferenceProvenance;
use App\Enums\PreferenceSentiment;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecordHouseholdPreference implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly RecordPreference $recordPreference,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a food like or dislike stated by a person, or a clearly labelled low-stakes inference. Never use this for allergies or medical restrictions.';
    }

    public function handle(Request $request): Stringable|string
    {
        $personId = $request->integer('person_id');
        $person = $personId > 0 ? Person::query()->findOrFail($personId) : null;

        $preference = $this->recordPreference->handle(
            team: $this->team,
            user: $this->actor,
            subject: $request->string('subject')->toString(),
            sentiment: PreferenceSentiment::from($request->string('sentiment')->toString()),
            provenance: PreferenceProvenance::from($request->string('provenance')->toString()),
            person: $person,
            strength: max(1, min(5, $request->integer('strength', 3))),
            confidence: $request->float('confidence') ?: null,
        );

        return $preference->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()->description('Person identifier, omitted only for a family-wide preference.'),
            'subject' => $schema->string()->description('Food, cuisine, ingredient, or preparation preference.')->required(),
            'sentiment' => $schema->string()->description('Either like or dislike.')->required(),
            'provenance' => $schema->string()->description('stated, default, or inferred.')->required(),
            'strength' => $schema->integer()->description('Preference strength from 1 to 5.'),
            'confidence' => $schema->number()->description('Confidence from 0 to 1 for inferred preferences.'),
        ];
    }
}
