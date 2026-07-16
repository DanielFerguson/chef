<?php

namespace App\Ai\Agents;

use App\Ai\Data\RecipeDraftRequest;
use App\Enums\PreparationNoticeKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class RecipeDraftingAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly RecipeDraftRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        You prepare practical, complete household recipes for Chef.

        Return one usable recipe for the selected meal in the supplied context. Use ordinary
        Australian supermarket ingredients, realistic quantities, and the requested serving count.
        Respect every explicit safety constraint. Preferences guide the recipe but are not allergies.
        Do not add an ingredient that conflicts with an explicit safety constraint. Keep the recipe
        faithful to the selected title, economical, and achievable within the estimated time where
        possible. Include quantities and units for shopping; use a null quantity only for genuinely
        variable ingredients such as salt to taste. Include concise sequential steps, equipment, and
        only preparation notices that materially help.

        Selected meal and household context:
        {$context}
        INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->min(1)->max(160)->required(),
            'summary' => $schema->string()->max(2000)->required(),
            'servings' => $schema->number()->min(0.25)->max(999)->required(),
            'prep_minutes' => $schema->integer()->min(0)->max(65535)->required(),
            'cook_minutes' => $schema->integer()->min(0)->max(65535)->required(),
            'ingredients' => $schema->array()->min(1)->items($schema->object([
                'name' => $schema->string()->min(1)->max(255)->required(),
                'quantity' => $schema->number()->min(0)->nullable()->required(),
                'unit' => $schema->string()->max(80)->nullable()->required(),
                'preparation' => $schema->string()->max(255)->nullable()->required(),
                'optional' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties())->required(),
            'steps' => $schema->array()->min(1)->items($schema->object([
                'instruction' => $schema->string()->min(1)->required(),
                'timer_minutes' => $schema->integer()->min(0)->max(65535)->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
            'equipment' => $schema->array()->items($schema->string()->min(1)->max(255))->required(),
            'notices' => $schema->array()->items($schema->object([
                'kind' => $schema->string()->enum(PreparationNoticeKind::class)->required(),
                'instruction' => $schema->string()->min(1)->required(),
                'lead_minutes' => $schema->integer()->min(0)->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
