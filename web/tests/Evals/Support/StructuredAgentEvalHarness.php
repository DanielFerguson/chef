<?php

namespace Tests\Evals\Support;

use App\Ai\Agents\MealPlanAdjustmentDraftingAgent;
use App\Ai\Agents\MealPlanRecipeDraftingAgent;
use App\Ai\Agents\RetailerProductRankingAgent;
use App\Ai\Agents\RetailerSearchRecoveryAgent;
use App\Ai\Data\MealPlanAdjustmentDraftRequest;
use App\Ai\Data\MealPlanRecipeDraftRequest;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerSearchRecoveryRequest;
use Closure;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final class StructuredAgentEvalHarness
{
    /** @return Closure(string): string */
    public static function recipeTask(): Closure
    {
        return function (string $input): string {
            $response = (new MealPlanRecipeDraftingAgent(self::recipeRequest()))->prompt($input);

            return self::json($response, 'recipe drafting');
        };
    }

    /** @return Closure(string): string */
    public static function adjustmentTask(): Closure
    {
        return function (string $input): string {
            $response = (new MealPlanAdjustmentDraftingAgent(self::adjustmentRequest()))->prompt($input);

            return self::json($response, 'meal-plan adjustment');
        };
    }

    /** @return Closure(string): string */
    public static function rankingTask(): Closure
    {
        return function (string $input): string {
            $response = (new RetailerProductRankingAgent(self::rankingRequest()))->prompt($input);

            return self::json($response, 'retailer ranking');
        };
    }

    /** @return Closure(string): string */
    public static function recoveryTask(): Closure
    {
        return function (string $input): string {
            $response = (new RetailerSearchRecoveryAgent(self::recoveryRequest()))->prompt($input);

            return self::json($response, 'retailer search recovery');
        };
    }

    public static function recipeRequest(): MealPlanRecipeDraftRequest
    {
        return new MealPlanRecipeDraftRequest(
            teamId: 11,
            mealPlanId: 21,
            householdName: 'Eval Kitchen',
            meals: [
                [
                    'planned_meal_id' => 101,
                    'title' => 'Peanut-free chicken satay',
                    'summary' => 'A safe weeknight satay-style chicken dinner.',
                    'servings' => 2,
                    'estimated_minutes' => 35,
                    'explicit_constraints' => [[
                        'kind' => 'allergy',
                        'subject' => 'Peanuts',
                        'details' => 'No peanuts or peanut-derived ingredients.',
                    ]],
                ],
                [
                    'planned_meal_id' => 102,
                    'title' => 'Tomato penne',
                    'summary' => 'A simple vegetable pasta dinner.',
                    'servings' => 3,
                    'estimated_minutes' => 30,
                    'explicit_constraints' => [],
                ],
            ],
            conversationContext: [[
                'message_id' => 501,
                'role' => 'user',
                'sources' => ['recent_plan_instruction'],
                'content' => 'Keep both dinners under 40 minutes and make the satay completely peanut-free.',
            ]],
        );
    }

    public static function adjustmentRequest(): MealPlanAdjustmentDraftRequest
    {
        return new MealPlanAdjustmentDraftRequest(
            teamId: 11,
            mealPlanId: 21,
            basketRunId: 31,
            kind: 'product_unavailable',
            planRevision: 7,
            currentSubtotalCents: 2_400,
            budgetTargetCents: 2_500,
            blockedRequirements: [[
                'requirement_id' => 301,
                'name' => 'Satay sauce',
                'form' => null,
                'quantity' => 1,
                'unit' => 'each',
            ]],
            meals: [
                [
                    'planned_meal_id' => 201,
                    'meal_slot_id' => 401,
                    'title' => 'Peanut-free chicken satay',
                    'summary' => 'The unavailable sauce belongs to this dinner.',
                    'estimated_minutes' => 35,
                    'estimated_cost_cents' => 1_400,
                ],
                [
                    'planned_meal_id' => 202,
                    'meal_slot_id' => 402,
                    'title' => 'Tomato penne',
                    'summary' => 'This meal has no blocked requirement.',
                    'estimated_minutes' => 30,
                    'estimated_cost_cents' => 1_000,
                ],
            ],
            explicitConstraints: [[
                'kind' => 'allergy',
                'subject' => 'Peanuts',
                'details' => 'No peanuts or peanut-derived ingredients.',
            ]],
        );
    }

    public static function rankingRequest(): RetailerProductRankingRequest
    {
        return new RetailerProductRankingRequest(
            teamId: 11,
            groceryPlanId: 41,
            requirements: [
                [
                    'requirement_id' => 501,
                    'name' => 'Tomato passata',
                    'form' => 'passata',
                    'quantity' => 500,
                    'unit' => 'ml',
                    'quantity_unknown' => false,
                    'candidates' => [
                        ['candidate_id' => 601, 'sku' => 'passata', 'title' => 'Tomato Passata 700ml', 'brand' => 'Coles', 'is_home_brand' => true, 'is_organic' => false, 'semantic_key' => 'tomato passata', 'pack_quantity' => 700, 'pack_unit' => 'ml', 'price_cents' => 220],
                        ['candidate_id' => 602, 'sku' => 'soup', 'title' => 'Tomato Soup 500ml', 'brand' => 'Coles', 'is_home_brand' => true, 'is_organic' => false, 'semantic_key' => 'tomato soup', 'pack_quantity' => 500, 'pack_unit' => 'ml', 'price_cents' => 180],
                    ],
                ],
                [
                    'requirement_id' => 502,
                    'name' => 'Penne pasta',
                    'form' => 'penne',
                    'quantity' => 500,
                    'unit' => 'g',
                    'quantity_unknown' => false,
                    'candidates' => [
                        ['candidate_id' => 603, 'sku' => 'penne', 'title' => 'Penne Pasta 500g', 'brand' => 'Coles', 'is_home_brand' => true, 'is_organic' => false, 'semantic_key' => 'penne pasta', 'pack_quantity' => 500, 'pack_unit' => 'g', 'price_cents' => 150],
                        ['candidate_id' => 604, 'sku' => 'spaghetti', 'title' => 'Spaghetti 500g', 'brand' => 'Coles', 'is_home_brand' => true, 'is_organic' => false, 'semantic_key' => 'spaghetti pasta', 'pack_quantity' => 500, 'pack_unit' => 'g', 'price_cents' => 130],
                    ],
                ],
            ],
        );
    }

    public static function recoveryRequest(): RetailerSearchRecoveryRequest
    {
        return new RetailerSearchRecoveryRequest(
            teamId: 11,
            groceryPlanId: 41,
            requirements: [
                [
                    'requirement_id' => 701,
                    'name' => 'Unsweetened coconut milk',
                    'form' => 'canned',
                    'attempted_queries' => ['unsweetened canned coconut milk', 'coconut milk 400ml'],
                ],
                [
                    'requirement_id' => 702,
                    'name' => 'Rice noodles',
                    'form' => 'thin',
                    'attempted_queries' => ['thin rice noodles', 'rice vermicelli'],
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('The structured eval response must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public static function records(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("The structured eval response must contain a {$key} array.");
        }

        $records = [];
        foreach ($value as $record) {
            if (! is_array($record)) {
                throw new RuntimeException("Every {$key} entry must be an object.");
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    public static function integers(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException("The structured eval response must contain a {$key} array.");
        }

        $integers = [];
        foreach ($value as $integer) {
            if (! is_int($integer)) {
                throw new RuntimeException("Every {$key} entry must be an integer.");
            }

            $integers[] = $integer;
        }

        return $integers;
    }

    private static function json(mixed $response, string $workload): string
    {
        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException("The {$workload} eval did not return structured output.");
        }

        return json_encode($response->structured, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
