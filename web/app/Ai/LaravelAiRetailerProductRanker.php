<?php

namespace App\Ai;

use App\Ai\Agents\RetailerProductRankingAgent;
use App\Ai\Contracts\RetailerProductRanker;
use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerProductRankingResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiRetailerProductRanker implements RetailerProductRanker
{
    public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult
    {
        $response = (new RetailerProductRankingAgent($request))
            ->prompt('Rank every supplied grocery requirement now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Retailer product ranking did not return structured output.');
        }

        return RetailerProductRankingResult::fromArray($response->structured);
    }
}
