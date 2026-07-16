<?php

namespace App\Ai\Tools;

use App\Actions\Households\CreateHouseholdPerson as CreateHouseholdPersonAction;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateHouseholdPerson implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly CreateHouseholdPersonAction $createPerson,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a named person who belongs to the household or regularly participates in meals. Use only when the user explicitly identifies that person.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->createPerson->handle(
            $this->team,
            $this->actor,
            $request->string('name')->toString(),
            $this->sourceMessage,
        )->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person’s name exactly as the user provided it.')->required(),
        ];
    }
}
