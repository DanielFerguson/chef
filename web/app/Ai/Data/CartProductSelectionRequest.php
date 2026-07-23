<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class CartProductSelectionRequest implements JsonSerializable
{
    /**
     * @param  array<int, array{
     *     shopping_list_item_id: int,
     *     name: string,
     *     quantity: float|null,
     *     unit: string|null,
     *     candidates: array<int, array{
     *         external_id: string,
     *         product_name: string,
     *         price: float|null,
     *         pack_size: string|null,
     *         confidence: float|null,
     *         in_stock: bool
     *     }>
     * }>  $items
     */
    public function __construct(public array $items) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values($data['items'] ?? []));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items];
    }
}
