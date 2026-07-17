<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ApplicationReadiness
{
    /** @return array{ready: bool, failures: array<int, string>} */
    public function inspect(): array
    {
        $failures = [];

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $failures[] = 'database';
        }

        try {
            $key = 'chef:readiness:'.str()->uuid();
            Cache::put($key, 'ready', 10);

            if (Cache::get($key) !== 'ready') {
                $failures[] = 'cache';
            }

            Cache::forget($key);
        } catch (Throwable) {
            $failures[] = 'cache';
        }

        return ['ready' => $failures === [], 'failures' => array_values(array_unique($failures))];
    }
}
