<?php

namespace App\Ai\Agents;

use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Enums\PreparationNoticeKind;
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
#[Timeout(180)]
class MealPlanRecipeDraftingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly MealPlanRecipeDraftRequest $request) {}

    public function instructions(): Stringable|string
    {
        $context = json_encode($this->request, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return <<<INSTRUCTIONS
        You prepare the complete set of practical household recipes for one finished Chef meal plan.

        Produce exactly one usable recipe for every supplied planned_meal_id in one response. Use the
        complete week as shared context so titles, ingredients, preparation, and leftovers make sense
        together. Use ordinary Australian supermarket ingredients, realistic quantities, and each
        meal's requested serving count. Respect every explicit safety constraint attached to that meal.
        Preferences guide recipes but are not allergies. Treat the supplied conversation as plan-level
        user intent: obey explicit timing, style, nutrition, ingredient, and variety requests unless they
        conflict with a safety constraint. Keep each recipe faithful to the selected meal, economical,
        high quality, and within any explicitly requested maximum total time.

        Include practical quantities and units, concise sequential steps, equipment, material
        preparation notices, and practical storage guidance. Return only supplied planned_meal_id values,
        exactly once each. Do not add or omit meals.

        Completed meal-plan context:
        {$context}
        INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.workloads.recipe_batch.model', 'gpt-5.6-sol');
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
            ? ['reasoning' => ['effort' => config('ai.workloads.recipe_batch.reasoning_effort', 'high')]]
            : [];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recipes' => $schema->array()->min(1)->items($schema->object([
                'planned_meal_id' => $schema->integer()->min(1)->required(),
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
                'storage_guidance' => $schema->string()->max(2000)->nullable()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
