<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class MealPlanAdjustmentDraftResult implements JsonSerializable
{
    /** @param list<array{planned_meal_id: int, meal_slot_id: int, title: string, summary: string|null, estimated_minutes: int|null, estimated_cost_cents: int|null, covered_requirement_ids: list<int>}> $items */
    public function __construct(public array $items) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(
            static fn (array $item): array => [
                'planned_meal_id' => (int) ($item['planned_meal_id'] ?? 0),
                'meal_slot_id' => (int) ($item['meal_slot_id'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'summary' => is_string($item['summary'] ?? null) ? $item['summary'] : null,
                'estimated_minutes' => is_numeric($item['estimated_minutes'] ?? null) ? (int) $item['estimated_minutes'] : null,
                'estimated_cost_cents' => is_numeric($item['estimated_cost_cents'] ?? null) ? (int) $item['estimated_cost_cents'] : null,
                'covered_requirement_ids' => array_values(array_map(
                    static fn ($requirementId): int => (int) $requirementId,
                    is_array($item['covered_requirement_ids'] ?? null) ? $item['covered_requirement_ids'] : [],
                )),
            ],
            is_array($data['items'] ?? null) ? $data['items'] : [],
        )));
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items];
    }
}
