<?php

namespace App\Ai\Agents;

use App\Ai\Data\RetailerProductRankingRequest;
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
class RetailerProductRankingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly RetailerProductRankingRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        Rank the already-discovered, already-hard-validated Coles candidates for each grocery requirement.

        Return exactly one ranking for every supplied requirement_id. Put candidates into ordered semantic
        suitability tiers. Every supplied candidate_id must occur exactly once across the non-empty tiers,
        with no unknown identifiers or duplicates. Rank
        semantic suitability first: ingredient identity, requested form or pasta shape, recipe use, and
        ordinary Australian household suitability. Pack quantity and price are visible context, but Chef
        applies household preferences and its price-versus-waste policy deterministically after your tiers. A low-confidence best
        guess is allowed because all supplied candidates already passed origin, stock, pack, price, and
        explicit-constraint evidence gates. Never invent a product, candidate, requirement, label claim,
        SKU, or identifier.

        Candidate context:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.retailer_selection.model', 'gpt-5.6-luna');
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
            ? ['reasoning' => ['effort' => config('ai.workloads.retailer_selection.reasoning_effort', 'medium')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rankings' => $schema->array()->min(1)->items($schema->object([
                'requirement_id' => $schema->integer()->min(1)->required(),
                'tiers' => $schema->array()
                    ->min(1)
                    ->items($schema->object([
                        'candidate_ids' => $schema->array()
                            ->min(1)
                            ->items($schema->integer()->min(1))
                            ->required(),
                    ])->withoutAdditionalProperties())
                    ->required(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
