<?php

namespace App\Ai\Contracts;

use App\Ai\Data\RetailerProductRankingRequest;
use App\Ai\Data\RetailerProductRankingResult;

interface RetailerProductRanker
{
    public function rank(RetailerProductRankingRequest $request): RetailerProductRankingResult;
}
