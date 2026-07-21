<?php

namespace App\Ai\Agents;

use App\Ai\Data\ShoppingListDraftRequest;
use App\Enums\ShoppingListItemCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Strict]
#[Timeout(120)]
class ShoppingListDraftingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly ShoppingListDraftRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        You consolidate Chef's exact recipe requirements into one practical Australian supermarket shopping list.

        Success means:
        - include every supplied requirement ID exactly once;
        - consolidate only duplicate and purchase-equivalent requirements;
        - use ordinary Australian supermarket names;
        - keep materially different ingredients separate rather than treating substitutions as facts;
        - use only the supplied Chef grocery categories;
        - reference only supplied requirement IDs;
        - respect every explicit safety constraint for the meals it applies to.

        Chef calculates quantities, units, optionality, and source meals deterministically after your grouping.
        Return the final groups only through the required structured output. Ingredient names must be unique after
        canonical grocery normalisation. Do not include explanations, quantities, units, retailer products, brands,
        prices, recipe instructions, package recommendations, substitutions, or ingredients not present in the input.

        Meal plan context:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.shopping_list.model', 'gpt-5.6-sol');
    }

    public function provider(): Lab
    {
        return Lab::OpenAI;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        return $provider === Lab::OpenAI
            ? ['reasoning' => ['effort' => config('ai.workloads.shopping_list.reasoning_effort', 'high')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->min(1)->items($schema->object([
                'name' => $schema->string()->min(1)->max(255)->required(),
                'category' => $schema->string()->enum(ShoppingListItemCategory::class)->required(),
                'source_requirement_ids' => $schema->array()->min(1)->items(
                    $schema->integer()->min(1)
                )->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
