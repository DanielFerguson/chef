<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class RetailerSearchRecoveryRequest implements JsonSerializable
{
    /**
     * @param  list<array{
     *     requirement_id: int,
     *     name: string,
     *     form: string|null,
     *     attempted_queries: list<string>
     * }>  $requirements
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
