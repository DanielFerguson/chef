<?php

namespace App\Console\Commands;

use App\Actions\Retailers\BuildRetailerOperationalMetrics;
use Illuminate\Console\Command;

class RetailerMetricsCommand extends Command
{
    protected $signature = 'retailer:metrics {--since=24h : Metrics window such as 30m, 24h, or 7d} {--json : Emit machine-readable JSON}';

    protected $description = 'Report safe aggregate retailer preparation metrics';

    public function handle(BuildRetailerOperationalMetrics $buildMetrics): int
    {
        $since = (string) $this->option('since');
        if (preg_match('/^(\d{1,4})(m|h|d)$/', $since, $matches) !== 1) {
            $this->components->error('The --since option must use minutes, hours, or days, for example 30m, 24h, or 7d.');

            return self::INVALID;
        }

        $amount = (int) $matches[1];
        $windowStart = match ($matches[2]) {
            'm' => now()->subMinutes($amount),
            'h' => now()->subHours($amount),
            'd' => now()->subDays($amount),
        };
        $metrics = $buildMetrics->handle($windowStart);

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $outcomes = $metrics['outcomes'];
            $rows = [];
            if (is_array($outcomes)) {
                foreach ($outcomes as $outcome => $count) {
                    if (is_string($outcome) && is_int($count)) {
                        $rows[] = [$outcome, $count];
                    }
                }
            }
            $this->table(['Outcome', 'Count'], $rows);
            $this->line('Stagehand fallbacks: '.$metrics['stagehand_fallback_count']);
        }

        return self::SUCCESS;
    }
}
