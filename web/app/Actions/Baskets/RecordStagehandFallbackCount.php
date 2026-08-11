<?php

namespace App\Actions\Baskets;

use App\Models\BasketRun;
use App\Retailer\Data\RetailerWorkerResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordStagehandFallbackCount
{
    public function handle(BasketRun $basketRun, RetailerWorkerResult $result): void
    {
        $rawCount = Arr::get($result->data, 'stagehand_fallback_count');
        if (! is_numeric($rawCount)) {
            return;
        }
        $count = max(0, min(100, (int) $rawCount));
        if ($count === 0) {
            return;
        }

        DB::transaction(function () use ($basketRun, $count): void {
            $locked = BasketRun::query()->lockForUpdate()->findOrFail($basketRun->id);
            $locked->update([
                'stagehand_fallback_count' => min(
                    1_000_000,
                    (int) $locked->stagehand_fallback_count + $count,
                ),
            ]);
        });

        Log::info('Retailer Stagehand fallback recorded.', [
            'fallback_count' => $count,
        ]);
    }
}
