<?php

namespace App\Ai\Contracts;

use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;

interface RecipeDrafter
{
    public function draft(RecipeDraftRequest $request): RecipeDraft;
}
