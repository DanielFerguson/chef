<?php

namespace App\Retailer\Data;

use App\Enums\RetailerWorkerResultStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnexpectedValueException;

readonly class RetailerWorkerResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public RetailerWorkerResultStatus $status,
        public ?string $reasonCode,
        public ?string $verificationChecksum,
        public array $data,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (Arr::get($payload, 'protocol') !== config('retailer.protocol')) {
            throw new UnexpectedValueException('The retailer worker returned an unsupported protocol.');
        }

        $status = RetailerWorkerResultStatus::tryFrom((string) Arr::get($payload, 'status'));
        if ($status === null) {
            throw new UnexpectedValueException('The retailer worker returned an invalid result status.');
        }

        $data = Arr::get($payload, 'data', []);
        if (! is_array($data)) {
            throw new UnexpectedValueException('The retailer worker result data must be an object.');
        }

        self::assertSafePayload($data);

        return new self(
            status: $status,
            reasonCode: is_string(Arr::get($payload, 'reason_code'))
                ? Arr::get($payload, 'reason_code')
                : null,
            verificationChecksum: is_string(Arr::get($payload, 'verification_checksum'))
                ? Arr::get($payload, 'verification_checksum')
                : null,
            data: $data,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === RetailerWorkerResultStatus::Succeeded;
    }

    /** @param array<array-key, mixed> $payload */
    private static function assertSafePayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), [
                'html',
                'snapshot',
                'screenshot',
                'recording',
                'live_view',
                'password',
                'cookie',
            ])) {
                throw new UnexpectedValueException("The retailer worker returned prohibited field [{$key}].");
            }

            if (is_array($value)) {
                self::assertSafePayload($value);
            }
        }
    }
}
