<?php

namespace App\Actions\Shopping;

use App\Enums\ShoppingListStatus;
use App\Models\ProductPreference;
use App\Models\Retailer;
use App\Models\RetailProduct;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MatchRetailProduct
{
    public function __construct(
        private readonly RecordShoppingListRevision $recordRevision,
        private readonly EnsureShoppingListIsEditable $ensureEditable,
    ) {}

    /** @param array{name: string, external_id?: string|null, product_url?: string|null, brand?: string|null, pack_quantity?: float|null, pack_unit?: string|null, price: float, pack_count?: int, preferred?: bool, accept_substitutes?: bool, maximum_price?: float|null, note?: string|null} $data */
    public function handle(ShoppingListItem $item, Retailer $retailer, User $user, array $data, int $expectedRevision): ShoppingListItem
    {
        if (! $user->can('update', $item)) {
            throw new AuthorizationException('You cannot match a product to this item.');
        }

        if (! $retailer->active) {
            throw ValidationException::withMessages(['retailer' => 'Choose an active retailer.']);
        }

        if ($data['price'] < 0 || ($data['pack_count'] ?? 1) < 1 || (($data['maximum_price'] ?? null) !== null && $data['maximum_price'] < 0)) {
            throw ValidationException::withMessages(['product' => 'Product prices and pack counts must be valid positive values.']);
        }

        return DB::transaction(function () use ($item, $retailer, $user, $data, $expectedRevision): ShoppingListItem {
            $shoppingList = $this->ensureEditable->handle($item->shoppingList);
            $externalId = filled($data['external_id'] ?? null)
                ? Str::squish((string) $data['external_id'])
                : $this->externalIdFromUrl($data['product_url'] ?? null);

            if (filled($data['product_url'] ?? null)) {
                $retailerHost = parse_url((string) $retailer->website_url, PHP_URL_HOST);
                $productHost = parse_url((string) $data['product_url'], PHP_URL_HOST);

                if ($externalId === null || ! is_string($retailerHost) || ! is_string($productHost) || ! hash_equals($retailerHost, $productHost)) {
                    throw ValidationException::withMessages(['product_url' => 'Use a valid product-detail URL from the selected retailer.']);
                }
            }
            $identity = [
                'retailer_id' => $retailer->id,
                ...($externalId !== null ? ['external_id' => $externalId] : [
                    'name' => Str::squish($data['name']),
                    'brand' => filled($data['brand'] ?? null) ? Str::squish($data['brand']) : null,
                    'pack_quantity' => $data['pack_quantity'] ?? null,
                    'pack_unit' => filled($data['pack_unit'] ?? null) ? Str::lower(Str::squish($data['pack_unit'])) : null,
                ]),
            ];
            $product = RetailProduct::query()->firstOrNew($identity);
            $product->fill([
                ...$identity,
                'name' => Str::squish($data['name']),
                'brand' => filled($data['brand'] ?? null) ? Str::squish($data['brand']) : null,
                'pack_quantity' => $data['pack_quantity'] ?? null,
                'pack_unit' => filled($data['pack_unit'] ?? null) ? Str::lower(Str::squish($data['pack_unit'])) : null,
                'current_price' => round($data['price'], 2),
                'currency' => 'AUD',
                'product_url' => filled($data['product_url'] ?? null) ? (string) $data['product_url'] : null,
                'last_seen_at' => now(),
            ])->save();
            $preference = null;

            if ($data['preferred'] ?? false) {
                $preferenceIdentity = hash('sha256', implode('|', [
                    'retailer:'.$retailer->id,
                    $item->ingredient_id === null
                        ? 'name:'.$item->normalized_name
                        : 'ingredient:'.$item->ingredient_id,
                ]));
                $preferenceValues = [
                    'team_id' => $item->team_id,
                    'identity_key' => $preferenceIdentity,
                    'retailer_id' => $retailer->id,
                    'ingredient_id' => $item->ingredient_id,
                    'normalized_item_name' => $item->normalized_name,
                    'preferred_brand' => $product->brand,
                    'preferred_pack' => collect([$product->pack_quantity, $product->pack_unit])->filter()->implode(' '),
                    'accept_substitutes' => $data['accept_substitutes'] ?? true,
                    'maximum_price' => $data['maximum_price'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                ProductPreference::query()->upsert(
                    [$preferenceValues],
                    ['team_id', 'identity_key'],
                    ['retailer_id', 'ingredient_id', 'normalized_item_name', 'preferred_brand', 'preferred_pack', 'accept_substitutes', 'maximum_price', 'note', 'updated_at'],
                );
                $preference = ProductPreference::query()
                    ->where('team_id', $item->team_id)
                    ->where('identity_key', $preferenceIdentity)
                    ->sole();
            }

            $packCount = max(1, (int) ($data['pack_count'] ?? 1));
            $estimatedTotal = round($packCount * $data['price'], 2);
            $item->productMatch()->updateOrCreate([], [
                'team_id' => $item->team_id,
                'retail_product_id' => $product->id,
                'product_preference_id' => $preference?->id,
                'selected_by_user_id' => $user->id,
                'pack_count' => $packCount,
                'estimated_total' => $estimatedTotal,
                'status' => 'selected',
                'preferred' => (bool) ($data['preferred'] ?? false),
                'selected_at' => now(),
            ]);
            $item->update(['estimated_price' => $estimatedTotal]);
            $shoppingList->update(['status' => ShoppingListStatus::Draft, 'completed_at' => null]);
            $this->recordRevision->handle($shoppingList, $user, 'Matched '.$product->name.' to '.$item->name, $expectedRevision);

            return $item->refresh()->load('productMatch.retailProduct.retailer');
        });
    }

    private function externalIdFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return preg_match('~/productdetails/(\d+)~i', $url, $matches) === 1
            ? $matches[1]
            : null;
    }
}
