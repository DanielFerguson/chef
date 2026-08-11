<?php

use App\Ai\Agents\MealPlanAdjustmentDraftingAgent;
use App\Ai\Agents\MealPlanRecipeDraftingAgent;
use App\Ai\Agents\RetailerProductRankingAgent;
use App\Ai\Agents\RetailerSearchRecoveryAgent;
use Tests\Evals\Support\StructuredAgentEvalHarness;

it('bakes authoritative identifiers and safety boundaries into every structured workload prompt', function () {
    $recipe = (string) (new MealPlanRecipeDraftingAgent(
        StructuredAgentEvalHarness::recipeRequest(),
    ))->instructions();
    $adjustment = (string) (new MealPlanAdjustmentDraftingAgent(
        StructuredAgentEvalHarness::adjustmentRequest(),
    ))->instructions();
    $ranking = (string) (new RetailerProductRankingAgent(
        StructuredAgentEvalHarness::rankingRequest(),
    ))->instructions();
    $recovery = (string) (new RetailerSearchRecoveryAgent(
        StructuredAgentEvalHarness::recoveryRequest(),
    ))->instructions();

    expect($recipe)->toContain('"planned_meal_id": 101')
        ->toContain('No peanuts or peanut-derived ingredients.')
        ->toContain('exactly once each')
        ->and($adjustment)->toContain('"requirement_id": 301')
        ->toContain('"meal_slot_id": 401')
        ->toContain('pending proposals for one human reapproval')
        ->and($ranking)->toContain('"candidate_id": 601')
        ->toContain('Every supplied candidate_id must occur exactly once')
        ->and($recovery)->toContain('"requirement_id": 701')
        ->toContain('at most 80 characters')
        ->toContain('never return a URL');
});
