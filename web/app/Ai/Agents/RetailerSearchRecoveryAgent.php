<?php

namespace App\Ai\Agents;

use App\Ai\Data\RetailerSearchRecoveryRequest;
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
#[Timeout(60)]
class RetailerSearchRecoveryAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly RetailerSearchRecoveryRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        Supply one final Coles product-search phrase for every unresolved grocery requirement.
        Return each supplied requirement_id exactly once. Use plain search text of at most 80 characters;
        never return a URL. Broaden product wording without weakening, replacing, or interpreting any
        safety constraint. Do not choose products or invent identifiers. The deterministic searches already
        attempted are supplied only so the final query can use different useful wording.

        Unresolved requirements:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.retailer_recovery.model', 'gpt-5.6-luna');
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
            ? ['reasoning' => ['effort' => config('ai.workloads.retailer_recovery.reasoning_effort', 'low')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'queries' => $schema->array()->min(1)->items($schema->object([
                'requirement_id' => $schema->integer()->min(1)->required(),
                'query' => $schema->string()->min(1)->max(80)->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
