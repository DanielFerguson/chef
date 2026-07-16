<?php

namespace App\Ai\Tools;

use App\Actions\Households\RecordConstraint;
use App\Enums\ConstraintKind;
use App\Models\Person;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecordSafetyConstraint implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly RecordConstraint $recordConstraint,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a safety or dietary constraint only after the user explicitly confirms it. Never infer an allergy or medical restriction.';
    }

    public function handle(Request $request): Stringable|string
    {
        $personId = $request->integer('person_id');
        $person = $personId > 0 ? Person::query()->findOrFail($personId) : null;

        $constraint = $this->recordConstraint->handle(
            team: $this->team,
            user: $this->actor,
            kind: ConstraintKind::from($request->string('kind')->toString()),
            subject: $request->string('subject')->toString(),
            explicitlyConfirmed: $request->boolean('explicitly_confirmed'),
            person: $person,
            details: $request->string('details')->toString() ?: null,
            severity: $request->string('severity')->toString() ?: null,
        );

        return $constraint->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()->description('Person identifier, omitted only for a family-wide constraint.'),
            'kind' => $schema->string()->description('allergy, medical, dietary, religious, accessibility, or other.')->required(),
            'subject' => $schema->string()->description('The constrained ingredient, food, or requirement.')->required(),
            'explicitly_confirmed' => $schema->boolean()->description('True only when a user explicitly stated or confirmed this constraint.')->required(),
            'details' => $schema->string()->description('Optional factual context.'),
            'severity' => $schema->string()->description('Optional user-stated severity.'),
        ];
    }
}
