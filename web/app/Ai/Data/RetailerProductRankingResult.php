<?php

namespace App\Ai\Data;

use JsonSerializable;

readonly class RetailerProductRankingResult implements JsonSerializable
{
    /** @param list<array<string, mixed>> $rankings */
    public function __construct(public array $rankings) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(
            static function (array $ranking): array {
                $tiers = array_values(array_map(
                    static fn (array $tier): array => [
                        'candidate_ids' => array_values(array_map(
                            static fn ($id): int => (int) $id,
                            $tier['candidate_ids'] ?? [],
                        )),
                    ],
                    $ranking['tiers'] ?? [],
                ));

                if ($tiers === [] && isset($ranking['ranked_candidate_ids'])) {
                    $tiers = array_values(array_map(
                        static fn ($id): array => ['candidate_ids' => [(int) $id]],
                        $ranking['ranked_candidate_ids'],
                    ));
                }

                return [
                    'requirement_id' => (int) $ranking['requirement_id'],
                    'tiers' => $tiers,
                    'confidence' => (float) ($ranking['confidence'] ?? 0.5),
                ];
            },
            $data['rankings'] ?? [],
        )));
    }

    /** @return array{rankings: list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return ['rankings' => $this->rankings];
    }
}
