<?php

namespace App\Actions\Privacy;

use App\Models\OperationalEvent;

class ExpireOperationalEvents
{
    public function handle(): int
    {
        $days = max(1, (int) config('chef.retention.audit_days', 730));

        return OperationalEvent::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->delete();
    }
}
