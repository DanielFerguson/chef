<?php

namespace App\Ai;

use App\Ai\Agents\ShoppingListDraftingAgent;
use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Ai\Exceptions\ShoppingListDraftUnavailable;
use Exception;
use Laravel\Ai\Responses\StructuredAgentResponse;

class LaravelAiShoppingListDrafter implements ShoppingListDrafter
{
    public function draft(ShoppingListDraftRequest $request): ShoppingListDraft
    {
        try {
            $response = (new ShoppingListDraftingAgent($request))->prompt('Consolidate the supplied requirements now.');
        } catch (Exception $exception) {
            throw new ShoppingListDraftUnavailable('The shopping-list provider was unavailable.', previous: $exception);
        }

        if (! $response instanceof StructuredAgentResponse) {
            throw new ShoppingListDraftUnavailable('Shopping-list drafting did not return structured output.');
        }

        if (! isset($response->structured['items']) || ! is_array($response->structured['items'])) {
            throw new ShoppingListDraftUnavailable('Shopping-list drafting returned malformed structured output.');
        }

        return ShoppingListDraft::fromArray($response->structured);
    }
}
