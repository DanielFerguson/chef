<?php

namespace App\Actions\Shopping;

use App\Enums\ShoppingListStatus;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOrderSnapshot
{
    public function handle(ShoppingList $shoppingList, User $user, float $actualTotal, ?Retailer $retailer = null): Order
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

        return DB::transaction(function () use ($shoppingList, $user, $actualTotal, $retailer): Order {
            $shoppingList->load('items.productMatch.retailProduct');
            $estimatedTotal = $shoppingList->items
                ->where('included', true)
                ->where('in_pantry', false)
                ->sum(fn ($item): float => (float) ($item->estimated_price ?? 0));
            $order = Order::query()->create([
                'team_id' => $shoppingList->team_id,
                'shopping_list_id' => $shoppingList->id,
                'retailer_id' => $retailer?->id,
                'recorded_by_user_id' => $user->id,
                'shopping_list_revision' => $shoppingList->revision,
                'status' => 'recorded',
                'currency' => 'AUD',
                'estimated_total' => round($estimatedTotal, 2),
                'actual_total' => round($actualTotal, 2),
                'recorded_at' => now(),
            ]);

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
}
