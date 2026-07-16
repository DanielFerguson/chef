<?php

namespace App\Http\Controllers;

use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\DeleteShoppingListItem;
use App\Actions\Shopping\UpdateShoppingListItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListItemController extends Controller
{
    public function store(Request $request, ShoppingList $shoppingList, AddShoppingListItem $add): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'unit' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
            'staple' => ['sometimes', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $add->handle(
            $shoppingList,
            $request->user(),
            $validated['name'],
            isset($validated['quantity']) ? (float) $validated['quantity'] : null,
            $validated['unit'] ?? null,
            $validated['note'] ?? null,
            $validated['staple'] ?? false,
            $validated['expected_revision'],
        );

        return back();
    }

    public function update(Request $request, ShoppingListItem $shoppingListItem, UpdateShoppingListItem $update): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'included' => ['sometimes', 'boolean'],
            'in_pantry' => ['sometimes', 'boolean'],
            'checked' => ['sometimes', 'boolean'],
            'expected_revision' => ['sometimes', 'integer', 'min:0'],
        ]);
        $expectedRevision = $validated['expected_revision'] ?? null;
        unset($validated['expected_revision']);

        if (array_keys($validated) !== ['checked'] && $expectedRevision === null) {
            $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);
        }

        $update->handle($shoppingListItem, $request->user(), $validated, $expectedRevision);

        return back();
    }

    public function destroy(Request $request, ShoppingListItem $shoppingListItem, DeleteShoppingListItem $delete): RedirectResponse
    {
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $delete->handle($shoppingListItem, $request->user(), $validated['expected_revision']);

        return back();
    }
}
