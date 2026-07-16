<?php

namespace App\Ai\Data;

final readonly class AssistantReply
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $content,
        public array $metadata = [],
    ) {}
}
