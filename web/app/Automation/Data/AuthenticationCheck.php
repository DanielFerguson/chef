<?php

namespace App\Automation\Data;

final readonly class AuthenticationCheck
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        public bool $authenticated,
        public string $reason,
        public bool $botDetected = false,
        public bool $sensitiveScreen = false,
        public array $diagnostics = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diagnostics
     */
    public static function fromPayload(array $payload, array $diagnostics = []): self
    {
        return new self(
            authenticated: (bool) ($payload['authenticated'] ?? false),
            reason: is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : 'Authentication could not be verified.',
            botDetected: (bool) ($payload['bot_detected'] ?? false),
            sensitiveScreen: (bool) ($payload['sensitive_screen'] ?? false),
            diagnostics: $diagnostics,
        );
    }
}
