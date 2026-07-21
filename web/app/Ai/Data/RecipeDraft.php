<?php

namespace App\Ai\Data;

readonly class RecipeDraft
{
    /**
     * @param  array<int, array{name: string, quantity: float|null, unit: string|null, preparation: string|null, optional: bool}>  $ingredients
     * @param  array<int, array{instruction: string, timer_minutes: int|null}>  $steps
     * @param  array<int, string>  $equipment
     * @param  array<int, array{kind: string, instruction: string, lead_minutes: int|null}>  $notices
     */
    public function __construct(
        public string $title,
        public ?string $summary,
        public float $servings,
        public ?int $prepMinutes,
        public ?int $cookMinutes,
        public array $ingredients,
        public array $steps,
        public array $equipment,
        public array $notices,
        public ?string $storageGuidance = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            summary: filled($data['summary'] ?? null) ? (string) $data['summary'] : null,
            servings: (float) ($data['servings'] ?? 0),
            prepMinutes: isset($data['prep_minutes']) ? (int) $data['prep_minutes'] : null,
            cookMinutes: isset($data['cook_minutes']) ? (int) $data['cook_minutes'] : null,
            ingredients: array_values($data['ingredients'] ?? []),
            steps: array_values($data['steps'] ?? []),
            equipment: array_values($data['equipment'] ?? []),
            notices: array_values($data['notices'] ?? []),
            storageGuidance: filled($data['storage_guidance'] ?? null) ? (string) $data['storage_guidance'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'servings' => $this->servings,
            'prep_minutes' => $this->prepMinutes,
            'cook_minutes' => $this->cookMinutes,
            'ingredients' => $this->ingredients,
            'steps' => $this->steps,
            'equipment' => $this->equipment,
            'notices' => $this->notices,
            'storage_guidance' => $this->storageGuidance,
        ];
    }
}
