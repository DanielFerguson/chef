<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\ShoppingListDrafter;
use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;
use App\Ai\Exceptions\ShoppingListDraftUnavailable;

class DisabledShoppingListDrafter implements ShoppingListDrafter
{
    public function draft(ShoppingListDraftRequest $request): ShoppingListDraft
    {
        throw new ShoppingListDraftUnavailable('One-shot shopping generation is disabled in normal tests.');
    }
}
