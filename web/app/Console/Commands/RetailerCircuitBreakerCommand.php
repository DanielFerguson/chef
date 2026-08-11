<?php

namespace App\Console\Commands;

use App\Actions\Retailers\InspectRetailerOperationalReadiness;
use App\Actions\Retailers\RuntimeRetailerMutationCircuitBreaker;
use App\Retailer\Data\RetailerCircuitBreakerStatus;
use Illuminate\Console\Command;

class RetailerCircuitBreakerCommand extends Command
{
    protected $signature = 'retailer:circuit-breaker {action : status, open, or close} {--reason= : Required reason for open and close actions} {--json : Emit machine-readable JSON}';

    protected $description = 'Inspect or operate the fail-closed retailer mutation circuit breaker';

    public function handle(
        RuntimeRetailerMutationCircuitBreaker $circuitBreaker,
        InspectRetailerOperationalReadiness $inspectReadiness,
    ): int {
        $action = $this->argument('action');

        if (! in_array($action, ['status', 'open', 'close'], true)) {
            $this->components->error('Action must be status, open, or close.');

            return self::INVALID;
        }

        if ($action === 'status') {
            return $this->renderStatus($circuitBreaker->status());
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->components->error('The --reason option is required.');

            return self::INVALID;
        }

        if ($action === 'close') {
            $readiness = $inspectReadiness->handle(true);
            if (! $readiness['infrastructure_ready']
                || ! $readiness['features']['experience']
                || ! $readiness['features']['discovery']
                || ! $readiness['features']['mutation']
                || $readiness['features']['deployment_circuit_breaker_open']) {
                $this->components->error('The deployment flags and retailer infrastructure are not ready.');

                return self::FAILURE;
            }
        }

        $status = $action === 'open'
            ? $circuitBreaker->open($reason)
            : $circuitBreaker->close($reason);

        return $this->renderStatus($status);
    }

    private function renderStatus(RetailerCircuitBreakerStatus $status): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($status->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info("Runtime retailer mutation circuit breaker is {$status->state}.");
            $this->line('Reason: '.$status->reason);
        }

        return $status->available ? self::SUCCESS : self::FAILURE;
    }
}
