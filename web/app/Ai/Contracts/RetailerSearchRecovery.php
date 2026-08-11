<?php

namespace App\Ai\Contracts;

use App\Ai\Data\RetailerSearchRecoveryRequest;
use App\Ai\Data\RetailerSearchRecoveryResult;

interface RetailerSearchRecovery
{
    public function recover(RetailerSearchRecoveryRequest $request): RetailerSearchRecoveryResult;
}
