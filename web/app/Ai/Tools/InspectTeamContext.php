<?php

namespace App\Ai\Tools;

use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectTeamContext implements Tool
{
    public function __construct(private readonly Team $team) {}

    public function description(): Stringable|string
    {
        return 'Inspect the current family members, stated or inferred preferences, and explicitly confirmed safety constraints.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->team->load(['people.preferences', 'people.constraints', 'preferences', 'constraints'])
            ->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
