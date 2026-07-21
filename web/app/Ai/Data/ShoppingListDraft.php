<?php

namespace App\Ai\Data;

readonly class ShoppingListDraft
{
    /**
     * @param  array<int, array{name: string, category: string, source_requirement_ids: array<int, int>}>  $items
     */
    public function __construct(public array $items) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values($data['items'] ?? []));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items];
    }
}
