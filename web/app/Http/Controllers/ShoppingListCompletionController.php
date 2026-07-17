<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\CompleteShoppingList;
use App\Models\ShoppingList;
use App\Support\OperationalMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListCompletionController extends Controller
{
    public function __invoke(Request $request, ShoppingList $shoppingList, CompleteShoppingList $complete, OperationalMetrics $metrics): RedirectResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $complete->handle($shoppingList, $request->user(), $validated['expected_revision']);
        $metrics->recordProduct($shoppingList->team, $request->user(), 'shopping_list_completed', [
            'shopping_list_id' => $shoppingList->id,
        ]);

        return back();
    }
}
