<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\MatchRetailProduct;
use App\Models\Retailer;
use App\Models\ShoppingListItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductMatchController extends Controller
{
    public function __invoke(Request $request, ShoppingListItem $shoppingListItem, MatchRetailProduct $match): RedirectResponse
    {
        $validated = $request->validate([
            'retailer_id' => ['required', 'integer', 'exists:retailers,id'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'pack_quantity' => ['nullable', 'numeric', 'min:0'],
            'pack_unit' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'pack_count' => ['required', 'integer', 'min:1', 'max:999'],
            'preferred' => ['required', 'boolean'],
            'accept_substitutes' => ['required', 'boolean'],
            'maximum_price' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $retailer = Retailer::query()
            ->where('active', true)
            ->whereKey((int) $validated['retailer_id'])
            ->firstOrFail();
        $match->handle($shoppingListItem, $retailer, $request->user(), $validated, $validated['expected_revision']);

        return back();
    }
}
