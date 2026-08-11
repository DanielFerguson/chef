<?php

namespace App\Actions\Retailers;

use App\Enums\BasketRunStatus;
use App\Models\BasketRun;
use App\Models\BasketRunStatusTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BuildRetailerOperationalMetrics
{
    /** @return array<string, mixed> */
    public function handle(CarbonInterface $since): array
    {
        $transitions = BasketRunStatusTransition::query()
            ->where('transitioned_at', '>=', $since)
            ->oldest('transitioned_at')
            ->get(['basket_run_id', 'from_status', 'to_status', 'duration_ms']);
        $durations = $transitions->pluck('duration_ms')->map(fn ($duration): int => (int) $duration)->sort()->values();
        $runIds = $transitions->pluck('basket_run_id')->unique()->values();

        return [
            'window_started_at' => $since->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'outcomes' => [
                'ready' => $this->toStatusCount($transitions, BasketRunStatus::Ready),
                'failed' => $this->toStatusCount($transitions, BasketRunStatus::Failed),
                'uncertain' => $this->toStatusCount($transitions, BasketRunStatus::Uncertain),
                'needs_attention' => $this->toStatusCount($transitions, BasketRunStatus::NeedsAttention),
                'restored' => $this->toStatusCount($transitions, BasketRunStatus::Restored),
            ],
            'restoration' => [
                'completed' => $transitions->where('from_status', BasketRunStatus::Restoring->value)
                    ->where('to_status', BasketRunStatus::Restored->value)
                    ->count(),
                'incomplete' => $transitions->where('from_status', BasketRunStatus::Restoring->value)
                    ->whereIn('to_status', [
                        BasketRunStatus::Failed->value,
                        BasketRunStatus::Uncertain->value,
                        BasketRunStatus::NeedsAttention->value,
                    ])->count(),
            ],
            'transitions' => [
                'count' => $durations->count(),
                'average_duration_ms' => $durations->isEmpty() ? 0 : (int) round($durations->average()),
                'p50_duration_ms' => $this->percentile($durations, 0.5),
                'p95_duration_ms' => $this->percentile($durations, 0.95),
                'maximum_duration_ms' => $durations->last() ?? 0,
            ],
            'stagehand_fallback_count' => $runIds->isEmpty()
                ? 0
                : (int) BasketRun::query()->whereKey($runIds)->sum('stagehand_fallback_count'),
        ];
    }

    /** @param Collection<int, BasketRunStatusTransition> $transitions */
    private function toStatusCount(Collection $transitions, BasketRunStatus $status): int
    {
        return $transitions->where('to_status', $status->value)->count();
    }

    /** @param Collection<int, int> $values */
    private function percentile(Collection $values, float $percentile): int
    {
        if ($values->isEmpty()) {
            return 0;
        }

        $index = (int) ceil($percentile * $values->count()) - 1;

        return (int) $values->get(max(0, $index));
    }
}
