<?php

namespace App\Retailer\Data;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public bool $ok,
        public array $payload = [],
        public ?string $errorMessage = null,
    ) {}
}
