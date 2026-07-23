<?php

namespace App\Ai\Contracts;

use App\Ai\Data\CartProductSelectionRequest;
use App\Ai\Data\CartProductSelectionResult;

interface CartProductCandidateSelector
{
    public function select(CartProductSelectionRequest $request): CartProductSelectionResult;
}
