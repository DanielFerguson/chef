<?php

namespace App\Actions\Automation;

use App\Automation\ConnectionCredentials;
use App\Automation\RetailerOriginPolicy;
use App\Enums\BrowserConnectionStatus;
use App\Models\BrowserConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class CreateBrowserPairing
{
    public function __construct(
        private readonly ConnectionCredentials $credentials,
        private readonly RetailerOriginPolicy $origins,
    ) {}

    /** @return array{connection: BrowserConnection, pairing_code: string} */
    public function handle(Team $team, User $user): array
    {
        if (! $user->can('manageIntegrations', $team)) {
            throw new AuthorizationException('You cannot pair a browser with this household.');
        }

        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        $connection = BrowserConnection::query()->create([
            'uuid' => (string) Str::uuid(),
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status' => BrowserConnectionStatus::Pending,
            'pairing_code_hash' => $this->credentials->pairingHash($code),
            'allowed_origins' => $this->origins->allOrigins(),
            'expires_at' => now()->addMinutes(10),
        ]);

        return ['connection' => $connection, 'pairing_code' => $code];
    }
}
