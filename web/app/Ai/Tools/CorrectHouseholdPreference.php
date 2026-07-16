<?php

namespace App\Ai\Tools;

use App\Actions\Households\CorrectPreference;
use App\Models\Message;
use App\Models\Person;
use App\Models\Preference;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CorrectHouseholdPreference implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly Message $correctionMessage,
        private readonly CorrectPreference $correctPreference,
    ) {}

    public function description(): Stringable|string
    {
        return 'Correct a preference that was assigned to the wrong person or to the family. This supersedes the wrong active record and preserves both sources.';
    }

    public function handle(Request $request): Stringable|string
    {
        $incorrect = Preference::query()
            ->where('team_id', $this->team->id)
            ->findOrFail($request->integer('preference_id'));
        $personId = $request->integer('corrected_person_id');
        $person = $personId > 0
            ? Person::query()->where('team_id', $this->team->id)->findOrFail($personId)
            : null;

        return $this->correctPreference->handle(
            $incorrect,
            $this->actor,
            $person,
            $this->correctionMessage,
            $request->string('evidence_quote')->toString(),
            $request->string('person_reference')->toString() ?: null,
        )->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'preference_id' => $schema->integer()->description('Wrong active preference identifier from InspectTeamContext.')->required(),
            'corrected_person_id' => $schema->integer()->description('Correct person identifier, or omit only when the correction says it is family-wide.'),
            'person_reference' => $schema->string()->description('Exact name or pronoun in the correction quote identifying the correct person.'),
            'evidence_quote' => $schema->string()->description('Exact words from the current user correction that include the food and correct owner.')->required(),
        ];
    }
}
