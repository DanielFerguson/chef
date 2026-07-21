<?php

namespace App\Automation\Data;

use App\Enums\AutomationRunItemStatus;

final readonly class ItemPreparationResult
{
    /** @param array<string, mixed>|null $product */
    public function __construct(
        public AutomationRunItemStatus $status,
        public ?array $product = null,
        public ?string $reason = null,
        public bool $requiresComputerUse = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $status = AutomationRunItemStatus::tryFrom((string) ($payload['status'] ?? ''));

        return new self(
            status: $status ?? AutomationRunItemStatus::Searching,
            product: is_array($payload['product'] ?? null) ? $payload['product'] : null,
            reason: is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : ($status === null ? 'The deterministic controls could not verify this item.' : null),
            requiresComputerUse: $status === null || (bool) ($payload['requires_computer_use'] ?? false),
        );
    }
}
