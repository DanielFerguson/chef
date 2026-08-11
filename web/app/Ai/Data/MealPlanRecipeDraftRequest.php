<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class MealPlanRecipeDraftRequest implements JsonSerializable
{
    /**
     * @param  array<int, array<string, mixed>>  $meals
     * @param  array<int, array{message_id: int, role: string, sources: list<string>, content: string}>  $conversationContext
     */
    public function __construct(
        public int $teamId,
        public int $mealPlanId,
        public string $householdName,
        public array $meals,
        public array $conversationContext = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            teamId: (int) $data['team_id'],
            mealPlanId: (int) $data['meal_plan_id'],
            householdName: (string) $data['household_name'],
            meals: array_values($data['meals'] ?? []),
            conversationContext: array_values($data['conversation_context'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'team_id' => $this->teamId,
            'meal_plan_id' => $this->mealPlanId,
            'household_name' => $this->householdName,
            'meals' => $this->meals,
            'conversation_context' => $this->conversationContext,
        ];
    }
}
