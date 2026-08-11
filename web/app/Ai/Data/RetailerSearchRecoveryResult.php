<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class RetailerSearchRecoveryResult implements JsonSerializable
{
    /** @param list<array{requirement_id: int, query: string}> $queries */
    public function __construct(public array $queries) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(
            static fn (array $query): array => [
                'requirement_id' => (int) ($query['requirement_id'] ?? 0),
                'query' => (string) ($query['query'] ?? ''),
            ],
            is_array($data['queries'] ?? null) ? $data['queries'] : [],
        )));
    }

    /** @return array{queries: list<array{requirement_id: int, query: string}>} */
    public function jsonSerialize(): array
    {
        return ['queries' => $this->queries];
    }
}
