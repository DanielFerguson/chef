<?php

namespace App\Automation\Data;

final readonly class AuthenticationCheck
{
    public function __construct(
        public bool $authenticated,
        public string $reason,
        public bool $botDetected = false,
        public bool $sensitiveScreen = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            authenticated: (bool) ($payload['authenticated'] ?? false),
            reason: is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : 'Authentication could not be verified.',
            botDetected: (bool) ($payload['bot_detected'] ?? false),
            sensitiveScreen: (bool) ($payload['sensitive_screen'] ?? false),
        );
    }
}
