<?php

namespace App\Actions\Automation;

use App\Models\RetailerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcquireRetailerConnectionLease
{
    public function handle(RetailerConnection $connection): string
    {
        return DB::transaction(function () use ($connection): string {
            $locked = RetailerConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->lease_expires_at?->isFuture() && $locked->lease_owner !== null) {
                throw ValidationException::withMessages([
                    'connection' => 'This Woolworths connection is already in use. Wait for the current session to finish or cancel it.',
                ]);
            }

            $token = (string) Str::uuid();
            $locked->update([
                'lease_owner' => 'reservation:'.$token,
                'lease_expires_at' => now()->addSeconds((int) config('automation.lease_seconds', 900)),
            ]);

            return $token;
        });
    }
}
