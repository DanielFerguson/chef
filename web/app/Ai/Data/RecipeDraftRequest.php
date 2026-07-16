<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class RecipeDraftRequest implements JsonSerializable
{
    /**
     * @param  array<int, array{owner: string, subject: string, sentiment: string, provenance: string}>  $preferences
     * @param  array<int, array{owner: string, kind: string, subject: string, details: string|null, severity: string|null}>  $constraints
     * @param  array<int, array{title: string, date: string, kind: string}>  $otherMeals
     */
    public function __construct(
        public int $teamId,
        public int $mealPlanId,
        public int $plannedMealId,
        public string $mealDate,
        public string $mealKind,
        public ?int $proposalId,
        public ?int $sourceMessageId,
        public string $householdName,
        public string $title,
        public ?string $summary,
        public float $servings,
        public ?int $estimatedMinutes,
        public array $preferences,
        public array $constraints,
        public array $otherMeals,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            teamId: (int) $data['team_id'],
            mealPlanId: (int) $data['meal_plan_id'],
            plannedMealId: (int) $data['planned_meal_id'],
            mealDate: (string) $data['meal_date'],
            mealKind: (string) $data['meal_kind'],
            proposalId: isset($data['proposal_id']) ? (int) $data['proposal_id'] : null,
            sourceMessageId: isset($data['source_message_id']) ? (int) $data['source_message_id'] : null,
            householdName: (string) $data['household_name'],
            title: (string) $data['title'],
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
            servings: (float) $data['servings'],
            estimatedMinutes: isset($data['estimated_minutes']) ? (int) $data['estimated_minutes'] : null,
            preferences: array_values($data['preferences'] ?? []),
            constraints: array_values($data['constraints'] ?? []),
            otherMeals: array_values($data['other_meals'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'team_id' => $this->teamId,
            'meal_plan_id' => $this->mealPlanId,
            'planned_meal_id' => $this->plannedMealId,
            'meal_date' => $this->mealDate,
            'meal_kind' => $this->mealKind,
            'proposal_id' => $this->proposalId,
            'source_message_id' => $this->sourceMessageId,
            'household_name' => $this->householdName,
            'title' => $this->title,
            'summary' => $this->summary,
            'servings' => $this->servings,
            'estimated_minutes' => $this->estimatedMinutes,
            'preferences' => $this->preferences,
            'constraints' => $this->constraints,
            'other_meals' => $this->otherMeals,
        ];
    }
}
