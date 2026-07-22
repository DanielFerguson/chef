<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\SetShoppingFulfilment;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingFulfilmentController extends Controller
{
    public function __invoke(Request $request, ShoppingList $shoppingList, SetShoppingFulfilment $setFulfilment): RedirectResponse
    {
        $validated = $request->validate([
            'fulfilment_method' => ['required', 'in:delivery,pickup'],
        ]);
        $setFulfilment->handle($shoppingList, $request->user(), $validated['fulfilment_method']);

        return back();
    }
}
