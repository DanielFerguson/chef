<?php

namespace App\Actions\Shopping;

use App\Enums\AutomationRunStatus;
use App\Enums\ShoppingListStatus;
use App\Models\CartSnapshot;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOrderSnapshot
{
    public function handle(ShoppingList $shoppingList, User $user, float $actualTotal, ?Retailer $retailer = null, ?CartSnapshot $cartSnapshot = null): Order
    {
        if (! $user->can('update', $shoppingList)) {
            throw new AuthorizationException('You cannot record an order for this shopping list.');
        }

        if ($shoppingList->getRawOriginal('status') !== ShoppingListStatus::Completed->value) {
            throw ValidationException::withMessages(['shopping_list' => 'Complete the shopping list before recording the final order.']);
        }

        if ($actualTotal < 0) {
            throw ValidationException::withMessages(['actual_total' => 'The actual total cannot be negative.']);
        }

        if ($cartSnapshot !== null) {
            $cartSnapshot->loadMissing('run.retailerConnection', 'lines.runItem');
            $cartRetailer = $cartSnapshot->run->retailerConnection->retailer_id;

            if ($cartSnapshot->team_id !== $shoppingList->team_id
                || $cartSnapshot->run->shopping_list_id !== $shoppingList->id
                || $cartSnapshot->run->status !== AutomationRunStatus::ReadyForReview
                || ! $this->snapshotMatchesCurrentList($shoppingList, $cartSnapshot)) {
                throw ValidationException::withMessages(['cart_snapshot_id' => 'Use a verified cart from this exact shopping-list revision.']);
            }

            if ($retailer !== null && $retailer->id !== $cartRetailer) {
                throw ValidationException::withMessages(['retailer_id' => 'The retailer must match the verified cart.']);
            }

            $retailer ??= Retailer::query()->findOrFail($cartRetailer);
        }

        return DB::transaction(function () use ($shoppingList, $user, $actualTotal, $retailer, $cartSnapshot): Order {
            $shoppingList->load('items.productMatch.retailProduct');
            $estimatedTotal = $shoppingList->items
                ->where('included', true)
                ->where('in_pantry', false)
                ->sum(fn ($item): float => (float) ($item->estimated_price ?? 0));
            $order = Order::query()->create([
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'cart_snapshot_id' => $cartSnapshot?->id,
                'retailer_id' => $retailer?->id,
                'recorded_by_user_id' => $user->id,
                'shopping_list_revision' => $shoppingList->revision,
                'status' => 'recorded',
                'currency' => 'AUD',
                'estimated_total' => round($estimatedTotal, 2),
                'actual_total' => round($actualTotal, 2),
                'recorded_at' => now(),
            ]);

            if ($cartSnapshot !== null) {
                foreach ($cartSnapshot->lines->where('pre_existing', false)->filter(
                    fn ($line): bool => ! in_array($line->classification->value, ['unavailable', 'unresolved'], true),
                ) as $line) {
                    $requestedName = $line->runItem?->requirement_snapshot['name'] ?? null;
                    $order->lines()->create([
                        'team_id' => $shoppingList->team_id,
                        'shopping_list_item_id' => $line->shopping_list_item_id,
                        'retail_product_id' => null,
                        'retailer_product_identifier' => $line->external_product_id,
                        'product_name' => $line->product_name,
                        'brand' => null,
                        'pack' => $line->unit,
                        'quantity' => max(1, (int) ceil((float) ($line->quantity ?? 1))),
                        'unit_price' => $line->unit_price,
                        'total_price' => $line->total_price,
                        'substituted_from_name' => $line->classification->value === 'substituted'
                            ? $requestedName
                            : null,
                    ]);
                }

                return $order->load('lines');
            }

            foreach ($shoppingList->items->where('included', true)->where('in_pantry', false) as $item) {
                $match = $item->productMatch()->with('retailProduct')->first();

                if ($match !== null) {
                    $product = $match->retailProduct;
                    $productName = $product->name;
                    $brand = $product->brand;
                    $pack = collect([$product->pack_quantity, $product->pack_unit])->filter()->implode(' ');
                    $quantity = $match->pack_count;
                    $unitPrice = $product->current_price;
                    $totalPrice = $match->estimated_total;
                } else {
                    $product = null;
                    $productName = $item->name;
                    $brand = null;
                    $pack = null;
                    $quantity = 1;
                    $unitPrice = null;
                    $totalPrice = $item->estimated_price;
                }

                $order->lines()->create([
                    'team_id' => $shoppingList->team_id,
                    'shopping_list_item_id' => $item->id,
                    'retail_product_id' => $product?->id,
                    'retailer_product_identifier' => $product?->external_id,
                    'product_name' => $productName,
                    'brand' => $brand,
                    'pack' => $pack,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ]);
            }

            return $order->load('lines');
        });
    }

    private function snapshotMatchesCurrentList(ShoppingList $shoppingList, CartSnapshot $cartSnapshot): bool
    {
        $frozenItems = $cartSnapshot->run->frozen_snapshot['items'] ?? null;

        if (! is_array($frozenItems)) {
            return false;
        }

        $frozen = collect($frozenItems)
            ->map(fn (array $item): array => [
                'id' => is_numeric($item['shopping_list_item_id'] ?? null) ? (int) $item['shopping_list_item_id'] : null,
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null,
                'unit' => filled($item['unit'] ?? null) ? (string) $item['unit'] : null,
            ])
            ->sortBy('id')
            ->values();
        $current = $shoppingList->items()
            ->where('included', true)
            ->where('in_pantry', false)
            ->get()
            ->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => is_numeric($item->quantity) ? (float) $item->quantity : null,
                'unit' => filled($item->unit) ? (string) $item->unit : null,
            ])
            ->sortBy('id')
            ->values();

        return $frozen->all() === $current->all();
    }
}
