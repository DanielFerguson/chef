<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class ShoppingListDraftRequest implements JsonSerializable
{
    /**
     * @param  array<int, array{id: int, title: string, date: string, kind: string, servings: float}>  $meals
     * @param  array<int, array{id: int, planned_meal_id: int, name: string, quantity: float|null, unit: string|null, optional: bool}>  $requirements
     * @param  array<int, array{team_id: int, planned_meal_id: int, recipe_ingredient_id: int, ingredient_id: int|null, name: string, canonical_key: string, quantity: float|null, unit: string|null, dimension: string|null, optional: bool}>  $sources
     * @param  array<int, array{owner: string, kind: string, subject: string, details: string|null, severity: string|null, planned_meal_ids: array<int, int>}>  $constraints
     */
    public function __construct(
        public array $meals,
        public array $requirements,
        public array $sources,
        public array $constraints,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'meals' => $this->meals,
            'requirements' => $this->requirements,
            'safety_constraints' => $this->constraints,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this, JSON_THROW_ON_ERROR));
    }
}
