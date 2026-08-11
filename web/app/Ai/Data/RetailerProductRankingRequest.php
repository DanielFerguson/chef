<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class RetailerProductRankingRequest implements JsonSerializable
{
    /**
     * @param list<array{
     *     requirement_id: int,
     *     name: string,
     *     form: string|null,
     *     quantity: float|null,
     *     unit: string|null,
     *     quantity_unknown: bool,
     *     candidates: list<array{
     *         candidate_id: int,
     *         sku: string,
     *         title: string,
     *         brand: string|null,
     *         is_home_brand: bool|null,
     *         is_organic: bool|null,
     *         semantic_key: string,
     *         pack_quantity: float|null,
     *         pack_unit: string|null,
     *         price_cents: int|null
     *     }>
     * }> $requirements
     */
    public function __construct(
        public int $teamId,
        public int $groceryPlanId,
        public array $requirements,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'team_id' => $this->teamId,
            'grocery_plan_id' => $this->groceryPlanId,
            'requirements' => $this->requirements,
        ];
    }
}
