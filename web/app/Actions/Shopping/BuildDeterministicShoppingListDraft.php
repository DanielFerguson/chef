<?php

namespace App\Actions\Shopping;

use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Enums\ShoppingListItemCategory;

class BuildDeterministicShoppingListDraft
{
    /**
     * Build the same grouping contract expected from the model, without making
     * an external call. Validation and quantity derivation still happen in the
     * shared validator.
     */
    public function handle(ShoppingListDraftRequest $request): ShoppingListDraft
    {
        $groups = [];

        foreach ($request->sources as $requirementId => $source) {
            $groups[$source['canonical_key']] ??= [
                'name' => $source['name'],
                'category' => ShoppingListItemCategory::classify($source['name'])->value,
                'source_requirement_ids' => [],
            ];
            $groups[$source['canonical_key']]['source_requirement_ids'][] = (int) $requirementId;
        }

        ksort($groups);

        return new ShoppingListDraft(array_values($groups));
    }
}
