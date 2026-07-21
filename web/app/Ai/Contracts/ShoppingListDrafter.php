<?php

namespace App\Ai\Contracts;

use App\Ai\Data\ShoppingListDraft;
use App\Ai\Data\ShoppingListDraftRequest;

interface ShoppingListDrafter
{
    public function draft(ShoppingListDraftRequest $request): ShoppingListDraft;
}
