<?php

namespace App\Ai\Data;

readonly class CartProductSelectionResult
{
    /**
     * @param  array<int, array{shopping_list_item_id: int, external_id: string|null, reason: string|null}>  $selections
     */
    public function __construct(public array $selections) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $selections = [];

        foreach (array_values($data['selections'] ?? []) as $selection) {
            if (! is_array($selection) || ! is_numeric($selection['shopping_list_item_id'] ?? null)) {
                continue;
            }

            $externalId = $selection['external_id'] ?? null;
            $selections[] = [
                'shopping_list_item_id' => (int) $selection['shopping_list_item_id'],
                'external_id' => is_string($externalId) && trim($externalId) !== '' ? trim($externalId) : null,
                'reason' => is_string($selection['reason'] ?? null) ? trim((string) $selection['reason']) : null,
            ];
        }

        return new self($selections);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['selections' => $this->selections];
    }
}
