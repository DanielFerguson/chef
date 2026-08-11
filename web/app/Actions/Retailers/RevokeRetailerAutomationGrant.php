<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Models\RetailerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RevokeRetailerAutomationGrant
{
    public function handle(RetailerConnection $connection, User $user): RetailerConnection
    {
        if (! $user->can('update', $connection)) {
            throw new AuthorizationException('Only the Coles account owner can revoke this consent.');
        }

        return DB::transaction(function () use ($connection): RetailerConnection {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $locked->basketRuns()
                ->whereIn('status', collect(BasketRunStatus::cases())
                    ->filter(fn (BasketRunStatus $status): bool => $status->isActive())
                    ->pluck('value'))
                ->update([
                    'status' => BasketRunStatus::WaitingForConnection->value,
                    'failure_code' => 'standing_consent_revoked',
                    'failure_message' => 'Reconnect consent before Chef changes the Coles basket.',
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);

            return $locked->refresh();
        });
    }
}
