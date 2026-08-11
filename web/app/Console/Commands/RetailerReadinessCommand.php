<?php

namespace App\Console\Commands;

use App\Actions\Retailers\InspectRetailerOperationalReadiness;
use Illuminate\Console\Command;

class RetailerReadinessCommand extends Command
{
    protected $signature = 'retailer:readiness {--json : Emit machine-readable JSON} {--no-queue-probe : Check queue configuration without dispatching a probe}';

    protected $description = 'Check retailer infrastructure and mutation readiness without contacting a retailer';

    public function handle(InspectRetailerOperationalReadiness $inspectReadiness): int
    {
        $result = $inspectReadiness->handle(! $this->option('no-queue-probe'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info($result['infrastructure_ready']
                ? 'Retailer infrastructure is ready.'
                : 'Retailer infrastructure is not ready.');
            $checks = $result['checks'];
            $rows = [];
            if (is_array($checks)) {
                foreach ($checks as $name => $check) {
                    if (is_string($name)
                        && is_array($check)
                        && is_bool($check['ok'] ?? null)
                        && is_string($check['code'] ?? null)) {
                        $rows[] = [
                            $name,
                            $check['ok'] ? 'ready' : 'blocked',
                            $check['code'],
                        ];
                    }
                }
            }
            $this->table(['Check', 'Result', 'Code'], $rows);
        }

        return $result['infrastructure_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
