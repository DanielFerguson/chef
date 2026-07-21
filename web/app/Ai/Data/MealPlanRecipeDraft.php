<?php

namespace App\Ai\Data;

readonly class MealPlanRecipeDraft
{
    /** @param array<int, array<string, mixed>> $recipes */
    public function __construct(public array $recipes) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values($data['recipes'] ?? []));
    }

    /** @return array{recipes: array<int, array<string, mixed>>} */
    public function toArray(): array
    {
        return ['recipes' => $this->recipes];
    }
}
