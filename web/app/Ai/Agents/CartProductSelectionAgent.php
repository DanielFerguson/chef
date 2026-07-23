<?php

namespace App\Ai\Agents;

use App\Ai\Data\CartProductSelectionRequest;
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
#[Timeout(90)]
class CartProductSelectionAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly CartProductSelectionRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        You choose Woolworths catalogue products for Chef shopping-list items that still need a decision.

        Rules:
        - Choose only from each item's supplied candidates. Never invent external_id values, URLs, brands, or products.
        - Prefer the correct product type and culinary fit for ordinary Australian home cooking.
        - Among acceptable fits, prefer the lowest shelf price. When pack_size implies a comparable unit price, prefer the cheaper unit price.
        - Never choose out-of-stock candidates.
        - Never choose pet food, seeds for planting, or other non-food variants when the requirement is a grocery ingredient.
        - If no candidate is a good fit, abstain by returning null for external_id.
        - Return exactly one selection object for every supplied shopping_list_item_id.

        Items awaiting selection:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.product_match.model', 'gpt-5.6-luna');
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
            ? ['reasoning' => ['effort' => config('ai.workloads.product_match.reasoning_effort', 'low')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'selections' => $schema->array()->min(1)->items($schema->object([
                'shopping_list_item_id' => $schema->integer()->min(1)->required(),
                'external_id' => $schema->string()->nullable()->required(),
                'reason' => $schema->string()->max(240)->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
