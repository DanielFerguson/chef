<?php

namespace App\Ai;

use App\Ai\Agents\RetailerSearchRecoveryAgent;
use App\Ai\Contracts\RetailerSearchRecovery;
use App\Ai\Data\RetailerSearchRecoveryRequest;
use App\Ai\Data\RetailerSearchRecoveryResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiRetailerSearchRecovery implements RetailerSearchRecovery
{
    public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
    {
        $response = (new RetailerSearchRecoveryAgent($request))
            ->prompt('Return the bounded recovery queries now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Retailer search recovery did not return structured output.');
        }

        return RetailerSearchRecoveryResult::fromArray($response->structured);
    }
}
