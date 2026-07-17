<?php

namespace App\Actions\Automation;

use App\Automation\ConnectionCredentials;
use App\Enums\BrowserConnectionStatus;
use App\Models\BrowserConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClaimBrowserConnection
{
    public function __construct(private readonly ConnectionCredentials $credentials) {}

    /** @return array{connection: BrowserConnection, token: string} */
    public function handle(string $pairingCode, string $name): array
    {
        return DB::transaction(function () use ($pairingCode, $name): array {
            $connection = BrowserConnection::query()
                ->where('pairing_code_hash', $this->credentials->pairingHash($pairingCode))
                ->lockForUpdate()
                ->first();

            if ($connection === null
                || $connection->status !== BrowserConnectionStatus::Pending
                || $connection->expires_at->isPast()) {
                throw ValidationException::withMessages(['pairing_code' => 'This pairing code is invalid or has expired.']);
            }

            $token = Str::random(80);
            $connection->update([
                'name' => trim($name),
                'status' => BrowserConnectionStatus::Active,
                'pairing_code_hash' => null,
                'token_hash' => $this->credentials->tokenHash($token),
                'paired_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);

            return ['connection' => $connection->refresh(), 'token' => $token];
        });
    }
}
