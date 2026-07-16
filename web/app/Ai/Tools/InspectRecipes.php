<?php

namespace App\Ai\Tools;

use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class InspectRecipes implements Tool
{
    public function __construct(private readonly Team $team) {}

    public function description(): Stringable|string
    {
        return 'Inspect the family recipe library, including exact current version identifiers, ingredients, timings, and preparation notices.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->team->recipes()
            ->with(['latestVersion.ingredients', 'latestVersion.preparationNotices'])
            ->orderBy('title')
            ->get()
            ->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
