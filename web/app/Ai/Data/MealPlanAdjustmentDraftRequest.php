<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class MealPlanAdjustmentDraftRequest implements JsonSerializable
{
    /**
     * @param  list<array{requirement_id: int, name: string, form: string|null, quantity: float|null, unit: string|null}>  $blockedRequirements
     * @param  list<array{planned_meal_id: int, meal_slot_id: int, title: string, summary: string|null, estimated_minutes: int|null, estimated_cost_cents: int|null}>  $meals
     * @param  list<array<string, mixed>>  $explicitConstraints
     */
    public function __construct(
        public int $teamId,
        public int $mealPlanId,
        public int $basketRunId,
        public string $kind,
        public int $planRevision,
        public ?int $currentSubtotalCents,
        public ?int $budgetTargetCents,
        public array $blockedRequirements,
        public array $meals,
        public array $explicitConstraints,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'team_id' => $this->teamId,
            'meal_plan_id' => $this->mealPlanId,
            'basket_run_id' => $this->basketRunId,
            'kind' => $this->kind,
            'plan_revision' => $this->planRevision,
            'current_subtotal_cents' => $this->currentSubtotalCents,
            'budget_target_cents' => $this->budgetTargetCents,
            'blocked_requirements' => $this->blockedRequirements,
            'meals' => $this->meals,
            'explicit_constraints' => $this->explicitConstraints,
        ];
    }
}
