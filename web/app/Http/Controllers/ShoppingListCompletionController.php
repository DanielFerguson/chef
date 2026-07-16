<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\CompleteShoppingList;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListCompletionController extends Controller
{
    public function __invoke(Request $request, ShoppingList $shoppingList, CompleteShoppingList $complete): RedirectResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $complete->handle($shoppingList, $request->user(), $validated['expected_revision']);

        return back();
    }
}
