<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\RetailerSearchRecovery;
use App\Ai\Data\RetailerSearchRecoveryRequest;
use App\Ai\Data\RetailerSearchRecoveryResult;

class DeterministicRetailerSearchRecovery implements RetailerSearchRecovery
{
    public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult
    {
        return new RetailerSearchRecoveryResult(array_map(
            static fn (array $requirement): array => [
                'requirement_id' => $requirement['requirement_id'],
                'query' => $requirement['name'],
            ],
            $request->requirements,
        ));
    }
}
