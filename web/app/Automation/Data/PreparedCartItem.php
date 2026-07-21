<?php

namespace App\Automation\Data;

final readonly class PreparedCartItem
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        public ItemPreparationResult $preparation,
        public CartInspection $before,
        public CartInspection $after,
        public array $diagnostics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diagnostics
     */
    public static function fromPayload(array $payload, array $diagnostics = []): self
    {
        return new self(
            preparation: ItemPreparationResult::fromPayload(
                is_array($payload['preparation'] ?? null) ? $payload['preparation'] : [],
            ),
            before: CartInspection::fromPayload(
                is_array($payload['before'] ?? null) ? $payload['before'] : [],
            ),
            after: CartInspection::fromPayload(
                is_array($payload['after'] ?? null) ? $payload['after'] : [],
            ),
            diagnostics: [
                ...$diagnostics,
                'item' => is_array($payload['timings'] ?? null) ? $payload['timings'] : [],
            ],
        );
    }
}
