<?php

namespace App\Ai;

use App\Ai\Agents\RecipeDraftingAgent;
use App\Ai\Contracts\RecipeDrafter;
use App\Ai\Data\RecipeDraft;
use App\Ai\Data\RecipeDraftRequest;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiRecipeDrafter implements RecipeDrafter
{
    public function draft(RecipeDraftRequest $request): RecipeDraft
    {
        $response = (new RecipeDraftingAgent($request))->prompt('Prepare the structured recipe now.');

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Recipe drafting did not return structured output.');
        }

        return RecipeDraft::fromArray($response->structured);
    }
}
