<?php

namespace App\Actions\Automation;

use App\Models\RetailerConnection;

class ReleaseRetailerConnectionLease
{
    public function handle(RetailerConnection $connection, ?string $expectedOwner = null): void
    {
        $query = RetailerConnection::query()->whereKey($connection->id);

        if ($expectedOwner !== null) {
            $query->where('lease_owner', $expectedOwner);
        }

        $query->update(['lease_owner' => null, 'lease_expires_at' => null]);
    }
}
