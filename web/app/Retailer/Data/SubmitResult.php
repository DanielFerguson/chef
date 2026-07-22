<?php

namespace App\Retailer\Data;

final readonly class SubmitResult
{
    public function __construct(
        public bool $ok,
        public ?string $retailerOrderReference = null,
        public ?string $confirmationText = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            ok: (bool) ($payload['ok'] ?? false),
            retailerOrderReference: is_string($payload['retailer_order_reference'] ?? null)
                ? $payload['retailer_order_reference']
                : null,
            confirmationText: is_string($payload['confirmation_text'] ?? null)
                ? $payload['confirmation_text']
                : null,
            errorMessage: is_string($payload['error_message'] ?? null)
                ? $payload['error_message']
                : null,
        );
    }
}
