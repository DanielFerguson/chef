<?php

namespace App\Ai\Agents;

use App\Ai\Data\MealPlanAdjustmentDraftRequest;
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
class MealPlanAdjustmentDraftingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly MealPlanAdjustmentDraftRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        Draft the smallest coherent set of replacement meal proposals needed to resolve the supplied
        product-unavailable or budget-overrun problem. Use only the existing planned_meal_id and its exact
        meal_slot_id. Cover every blocked requirement_id at least once and return no unknown identifiers.
        Preserve dates, participants, servings, and every explicit safety constraint. Never infer or weaken
        an allergy or medical restriction. For a budget overrun, make the proposed set materially cheaper.
        These are pending proposals for one human reapproval; never describe them as already accepted.

        Authoritative plan and recovery state:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.retailer_adjustment.model', 'gpt-5.6-sol');
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
            ? ['reasoning' => ['effort' => config('ai.workloads.retailer_adjustment.reasoning_effort', 'medium')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->min(1)->items($schema->object([
                'planned_meal_id' => $schema->integer()->min(1)->required(),
                'meal_slot_id' => $schema->integer()->min(1)->required(),
                'title' => $schema->string()->min(1)->max(255)->required(),
                'summary' => $schema->string()->max(2_000),
                'estimated_minutes' => $schema->integer()->min(1),
                'estimated_cost_cents' => $schema->integer()->min(1),
                'covered_requirement_ids' => $schema->array()->items($schema->integer()->min(1))->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
