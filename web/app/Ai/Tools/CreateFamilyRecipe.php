<?php

namespace App\Ai\Tools;

use App\Actions\Recipes\CreateRecipe;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateFamilyRecipe implements Tool
{
    public function __construct(
        private readonly Team $team,
        private readonly User $actor,
        private readonly Message $sourceMessage,
        private readonly CreateRecipe $createRecipe,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a structured family recipe when the user supplies or approves its title, ingredient list, and steps.';
    }

    public function handle(Request $request): Stringable|string
    {
        $title = $request->string('title')->toString();
        $ingredients = collect($request->array('ingredients'))->map(fn ($name) => ['name' => (string) $name])->all();
        $steps = collect($request->array('steps'))->map(fn ($instruction) => ['instruction' => (string) $instruction])->all();
        $prepMinutes = $request->integer('prep_minutes');
        $cookMinutes = $request->integer('cook_minutes');

        return $this->createRecipe->handle(
            $this->team,
            $this->actor,
            $title,
            $request->string('summary')->toString() ?: null,
            max(0.25, $request->float('servings', 2)),
            $prepMinutes >= 0 ? $prepMinutes : null,
            $cookMinutes >= 0 ? $cookMinutes : null,
            $ingredients,
            $steps,
            idempotencyKey: hash('sha256', 'recipe|'.$this->sourceMessage->id.'|'.mb_strtolower(trim($title))),
        )->load('latestVersion.ingredients', 'latestVersion.steps')->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Recipe title approved or supplied by the user.')->required(),
            'summary' => $schema->string()->description('Short practical description.'),
            'servings' => $schema->number()->description('Base number of servings.')->required(),
            'prep_minutes' => $schema->integer()->description('Hands-on preparation minutes.'),
            'cook_minutes' => $schema->integer()->description('Cooking minutes.'),
            'ingredients' => $schema->array()->items($schema->string())->description('Ingredient lines, including quantities when known.')->required(),
            'steps' => $schema->array()->items($schema->string())->description('Ordered cooking instructions.')->required(),
        ];
    }
}
