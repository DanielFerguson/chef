<?php

namespace App\Ai\Data;

final readonly class AssistantStreamChunk
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $type,
        public string $delta = '',
        public array $metadata = [],
    ) {}
}
