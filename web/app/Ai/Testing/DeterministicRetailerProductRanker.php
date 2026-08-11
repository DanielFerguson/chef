<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerProductRankingResult;

class DeterministicRetailerProductRanker implements RetailerProductRanker
{
    public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
    {
        $rankings = [];
        foreach ($request->requirements as $requirement) {
            $candidateIds = array_map(
                static fn (array $candidate): int => $candidate['candidate_id'],
                $requirement['candidates'],
            );
            sort($candidateIds);
            $rankings[] = [
                'requirement_id' => $requirement['requirement_id'],
                'tiers' => [['candidate_ids' => $candidateIds]],
                'confidence' => 0.5,
            ];
        }

        return new RetailerProductRankingResult($rankings);
    }
}
