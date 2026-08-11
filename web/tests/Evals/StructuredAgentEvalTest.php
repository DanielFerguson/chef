<?php

use Tests\Evals\Support\StructuredAgentEvalHarness;

it('drafts every supplied recipe exactly once while preserving explicit safety truth', function () {
    $evaluation = expect(StructuredAgentEvalHarness::recipeTask())
        ->prompt('Prepare the complete two-recipe batch now, preserving every supplied identifier and safety constraint.')
        ->toBeJson()
        ->toBeSafe()
        ->toSatisfy(
            'The output must contain exactly one practical recipe for planned_meal_id 101 and 102, respect the requested servings and under-40-minute limit, and make recipe 101 completely peanut-free without weakening or reinterpreting the explicit allergy.'
        );
    $payload = StructuredAgentEvalHarness::decode($evaluation->value);
    $plannedMealIds = [];
    foreach (StructuredAgentEvalHarness::records($payload, 'recipes') as $recipe) {
        $plannedMealId = $recipe['planned_meal_id'] ?? null;
        if (! is_int($plannedMealId)) {
            throw new RuntimeException('Every recipe must contain an integer planned_meal_id.');
        }

        $plannedMealIds[] = $plannedMealId;
    }
    sort($plannedMealIds);

    expect($plannedMealIds)->toBe([101, 102]);
});

it('drafts the smallest pending plan adjustment with only authoritative identifiers', function () {
    $evaluation = expect(StructuredAgentEvalHarness::adjustmentTask())
        ->prompt('Return the smallest coherent pending proposal needed to resolve the blocked requirement.')
        ->toBeJson()
        ->toBeSafe()
        ->toSatisfy(
            'The output must propose a safe replacement only for planned_meal_id 201 in meal_slot_id 401, cover requirement_id 301, preserve the peanut allergy, leave unrelated meal 202 alone, and not describe the proposal as already accepted.'
        );
    $payload = StructuredAgentEvalHarness::decode($evaluation->value);
    $items = StructuredAgentEvalHarness::records($payload, 'items');

    expect($items)->toHaveCount(1)
        ->and($items[0]['planned_meal_id'] ?? null)->toBe(201)
        ->and($items[0]['meal_slot_id'] ?? null)->toBe(401)
        ->and(StructuredAgentEvalHarness::integers($items[0], 'covered_requirement_ids'))->toContain(301);
});

it('ranks every validated candidate exactly once by semantic suitability', function () {
    $evaluation = expect(StructuredAgentEvalHarness::rankingTask())
        ->prompt('Rank every supplied candidate into semantic suitability tiers without inventing identifiers.')
        ->toBeJson()
        ->toSatisfy(
            'The output must rank requirement 501 and 502 exactly once, include candidate ids 601 through 604 exactly once, put tomato passata candidate 601 ahead of tomato soup 602, and put penne candidate 603 ahead of spaghetti 604.'
        );
    $payload = StructuredAgentEvalHarness::decode($evaluation->value);
    $rankings = [];
    foreach (StructuredAgentEvalHarness::records($payload, 'rankings') as $ranking) {
        $requirementId = $ranking['requirement_id'] ?? null;
        if (! is_int($requirementId)) {
            throw new RuntimeException('Every ranking must contain an integer requirement_id.');
        }

        $rankings[$requirementId] = $ranking;
    }
    ksort($rankings);

    expect(array_keys($rankings))->toBe([501, 502]);

    foreach ([501 => [601, 602], 502 => [603, 604]] as $requirementId => $expectedCandidateIds) {
        $candidateIds = [];
        foreach (StructuredAgentEvalHarness::records($rankings[$requirementId], 'tiers') as $tier) {
            array_push($candidateIds, ...StructuredAgentEvalHarness::integers($tier, 'candidate_ids'));
        }
        sort($candidateIds);

        expect($candidateIds)->toBe($expectedCandidateIds);
    }
});

it('returns one bounded novel text query for every unresolved requirement', function () {
    $evaluation = expect(StructuredAgentEvalHarness::recoveryTask())
        ->prompt('Return one final plain-text recovery query for each unresolved requirement now.')
        ->toBeJson()
        ->toSatisfy(
            'The output must return requirement ids 701 and 702 exactly once, use useful plain search text no longer than 80 characters, avoid URLs, and not repeat any supplied attempted query verbatim.'
        );
    $payload = StructuredAgentEvalHarness::decode($evaluation->value);
    $queries = StructuredAgentEvalHarness::records($payload, 'queries');
    $requirementIds = [];
    foreach ($queries as $query) {
        $requirementId = $query['requirement_id'] ?? null;
        if (! is_int($requirementId)) {
            throw new RuntimeException('Every recovery query must contain an integer requirement_id.');
        }

        $requirementIds[] = $requirementId;
    }
    sort($requirementIds);

    expect($requirementIds)->toBe([701, 702]);

    foreach ($queries as $query) {
        $searchText = $query['query'] ?? null;
        if (! is_string($searchText)) {
            throw new RuntimeException('Every recovery query must contain query text.');
        }

        expect(mb_strlen($searchText))->toBeLessThanOrEqual(80)
            ->and($searchText)->not->toMatch('/https?:\/\/|www\./i');
    }
});
