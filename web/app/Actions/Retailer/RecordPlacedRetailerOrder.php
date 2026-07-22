<?php

namespace App\Actions\Retailer;

use App\Enums\RetailerOrderRunItemStatus;
use App\Enums\RetailerOrderRunStatus;
use App\Models\Order;
use App\Models\RetailerOrderRun;
use App\Models\RetailerOrderRunItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPlacedRetailerOrder
{
    public function handle(
        RetailerOrderRun $run,
        User $user,
        ?string $retailerOrderReference = null,
        ?string $confirmationText = null,
    ): Order {
        $run = $run->fresh(['items', 'shoppingList', 'retailerConnection']);

        if ($run === null) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'That Woolworths order run no longer exists.',
            ]);
        }

        if ($run->status === RetailerOrderRunStatus::Placed) {
            $placement = is_array($run->confirmation) ? ($run->confirmation['placement'] ?? null) : null;
            $orderId = is_array($placement) && is_numeric($placement['order_id'] ?? null)
                ? (int) $placement['order_id']
                : null;

            if ($orderId !== null) {
                $existing = Order::query()->find($orderId);

                if ($existing !== null) {
                    return $existing->load('lines');
                }
            }

            $existing = Order::query()
                ->where('shopping_list_id', $run->shopping_list_id)
                ->where('status', 'placed')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        if (! in_array($run->status, [
            RetailerOrderRunStatus::SubmittingOrder,
            RetailerOrderRunStatus::AwaitingPlacementVerification,
        ], true)) {
            throw ValidationException::withMessages([
                'retailer_order_run' => 'Chef can only record a Woolworths order after submit or placement verification.',
            ]);
        }

        return DB::transaction(function () use ($run, $user, $retailerOrderReference, $confirmationText): Order {
            $locked = RetailerOrderRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status === RetailerOrderRunStatus::Placed) {
                $placement = is_array($locked->confirmation) ? ($locked->confirmation['placement'] ?? null) : null;
                $orderId = is_array($placement) && is_numeric($placement['order_id'] ?? null)
                    ? (int) $placement['order_id']
                    : null;

                if ($orderId !== null) {
                    $existing = Order::query()->find($orderId);

                    if ($existing !== null) {
                        return $existing->load('lines');
                    }
                }
            }

            if (! in_array($locked->status, [
                RetailerOrderRunStatus::SubmittingOrder,
                RetailerOrderRunStatus::AwaitingPlacementVerification,
            ], true)) {
                throw ValidationException::withMessages([
                    'retailer_order_run' => 'Chef can only record a Woolworths order after submit or placement verification.',
                ]);
            }

            $locked->loadMissing('items', 'shoppingList', 'retailerConnection');

            $lines = $locked->items
                ->filter(fn (RetailerOrderRunItem $item): bool => $item->status === RetailerOrderRunItemStatus::Matched)
                ->values();

            $estimatedTotal = 0.0;
            $orderLines = [];

            foreach ($lines as $item) {
                $matched = is_array($item->matched_product) ? $item->matched_product : [];
                $requirement = is_array($item->requirement_snapshot) ? $item->requirement_snapshot : [];
                $productName = (string) ($matched['product_name'] ?? $matched['name'] ?? $requirement['name'] ?? 'Ordered item');
                $quantity = $matched['quantity'] ?? $requirement['quantity'] ?? 1;
                $quantity = is_numeric($quantity) ? max(1, (int) ceil((float) $quantity)) : 1;
                $unitPrice = isset($matched['unit_price']) && is_numeric($matched['unit_price'])
                    ? (float) $matched['unit_price']
                    : null;
                $totalPrice = isset($matched['total_price']) && is_numeric($matched['total_price'])
                    ? (float) $matched['total_price']
                    : ($unitPrice !== null ? round($unitPrice * $quantity, 2) : null);

                if ($totalPrice !== null) {
                    $estimatedTotal += $totalPrice;
                }

                $orderLines[] = [
                    'team_id' => $locked->team_id,
                    'shopping_list_item_id' => $item->shopping_list_item_id,
                    'retail_product_id' => null,
                    'retailer_product_identifier' => isset($matched['external_id'])
                        ? (string) $matched['external_id']
                        : null,
                    'product_name' => $productName,
                    'brand' => isset($matched['brand']) && is_string($matched['brand']) ? $matched['brand'] : null,
                    'pack' => isset($matched['pack']) && is_string($matched['pack']) ? $matched['pack'] : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'substituted_from_name' => null,
                ];
            }

            $order = Order::query()->create([
                'team_id' => $locked->team_id,
                'shopping_list_id' => $locked->shopping_list_id,
                'cart_snapshot_id' => null,
                'retailer_id' => $locked->retailerConnection->retailer_id,
                'recorded_by_user_id' => $user->id,
                'shopping_list_revision' => $locked->shoppingList->revision,
                'status' => 'placed',
                'currency' => 'AUD',
                'estimated_total' => round($estimatedTotal, 2),
                'actual_total' => round($estimatedTotal, 2),
                'recorded_at' => now(),
            ]);

            foreach ($orderLines as $line) {
                $order->lines()->create($line);
            }

            $confirmation = is_array($locked->confirmation) ? $locked->confirmation : [];
            $confirmation['placement'] = [
                'order_id' => $order->id,
                'retailer_order_reference' => $retailerOrderReference,
                'confirmation_text' => $confirmationText,
                'recorded_at' => now()->toIso8601String(),
            ];

            $locked->update([
                'status' => RetailerOrderRunStatus::Placed,
                'retailer_order_reference' => $retailerOrderReference,
                'confirmation' => $confirmation,
                'failure_message' => null,
            ]);

            return $order->load('lines');
        });
    }
}
