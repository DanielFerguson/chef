<?php

namespace App\Retailer\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use UnexpectedValueException;

readonly class RetailerLiveSession
{
    public function __construct(
        public string $sessionId,
        public string $liveViewUrl,
        public CarbonImmutable $expiresAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $sessionId = Arr::get($data, 'session_id');
        $liveViewUrl = Arr::get($data, 'live_view_url');
        $expiresAt = Arr::get($data, 'expires_at');

        if (! is_string($sessionId) || trim($sessionId) === '') {
            throw new UnexpectedValueException('The retailer worker did not return a session identifier.');
        }

        if (! is_string($liveViewUrl) || ! str_starts_with($liveViewUrl, 'https://')) {
            throw new UnexpectedValueException('The retailer worker did not return a secure Live View URL.');
        }

        if (! is_string($expiresAt)) {
            throw new UnexpectedValueException('The retailer worker did not return a session expiry.');
        }

        return new self($sessionId, $liveViewUrl, CarbonImmutable::parse($expiresAt));
    }
}
