<?php

namespace App\Ai;

use App\Ai\Agents\CartProductSelectionAgent;
use App\Ai\Contracts\CartProductCandidateSelector;
use App\Ai\Data\CartProductSelectionRequest;
use App\Ai\Data\CartProductSelectionResult;
use Laravel\Ai\Responses\StructuredAgentResponse;
use UnexpectedValueException;

class LaravelAiCartProductCandidateSelector implements CartProductCandidateSelector
{
    public function select(CartProductSelectionRequest $request): CartProductSelectionResult
    {
        if ($request->items === []) {
            return new CartProductSelectionResult([]);
        }

        $response = (new CartProductSelectionAgent($request))->prompt(
            'Select the best-fit cheapest Woolworths catalogue candidate for each item, or abstain.',
        );

        if (! $response instanceof StructuredAgentResponse) {
            throw new UnexpectedValueException('Cart product selection did not return structured output.');
        }

        return CartProductSelectionResult::fromArray($response->structured);
    }
}
