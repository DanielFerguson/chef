<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\RecordOrderSnapshot;
use App\Models\Retailer;
use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderSnapshotController extends Controller
{
    public function __invoke(Request $request, ShoppingList $shoppingList, RecordOrderSnapshot $record): RedirectResponse
    {
        $validated = $request->validate([
            'actual_total' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'retailer_id' => ['nullable', 'integer', 'exists:retailers,id'],
        ]);
        $retailer = isset($validated['retailer_id'])
            ? Retailer::query()->whereKey((int) $validated['retailer_id'])->firstOrFail()
            : null;
        $record->handle($shoppingList, $request->user(), $validated['actual_total'], $retailer);

        return back();
    }
}
