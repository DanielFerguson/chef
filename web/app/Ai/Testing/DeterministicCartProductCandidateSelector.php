<?php

namespace App\Ai\Testing;

use App\Ai\Contracts\CartProductCandidateSelector;
use App\Ai\Data\CartProductSelectionRequest;
use App\Ai\Data\CartProductSelectionResult;

class DeterministicCartProductCandidateSelector implements CartProductCandidateSelector
{
    /** @var array<int, string|null>|null */
    public ?array $forcedExternalIdsByItemId = null;

    public bool $abstain = false;

    public function select(CartProductSelectionRequest $request): CartProductSelectionResult
    {
        $selections = [];

        foreach ($request->items as $item) {
            $itemId = (int) $item['shopping_list_item_id'];

            if ($this->forcedExternalIdsByItemId !== null && array_key_exists($itemId, $this->forcedExternalIdsByItemId)) {
                $selections[] = [
                    'shopping_list_item_id' => $itemId,
                    'external_id' => $this->forcedExternalIdsByItemId[$itemId],
                    'reason' => 'Forced test selection.',
                ];

                continue;
            }

            if ($this->abstain) {
                $selections[] = [
                    'shopping_list_item_id' => $itemId,
                    'external_id' => null,
                    'reason' => 'No confident catalogue fit.',
                ];

                continue;
            }

            $chosen = collect($item['candidates'] ?? [])
                ->filter(fn (array $candidate): bool => (bool) ($candidate['in_stock'] ?? true)
                    && filled($candidate['external_id'] ?? null))
                ->sortBy(fn (array $candidate): float => is_numeric($candidate['price'] ?? null)
                    ? (float) $candidate['price']
                    : INF)
                ->values()
                ->first();

            $selections[] = [
                'shopping_list_item_id' => $itemId,
                'external_id' => is_array($chosen) ? (string) $chosen['external_id'] : null,
                'reason' => is_array($chosen) ? 'Cheapest acceptable catalogue fit.' : 'No usable candidate.',
            ];
        }

        return new CartProductSelectionResult($selections);
    }
}
