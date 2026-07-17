<?php

namespace App\Console\Commands;

use App\Support\ReleaseReadiness;
use App\Support\ReleaseRuntimeProbe;
use Illuminate\Console\Command;

class CheckReleaseReadiness extends Command
{
    protected $signature = 'chef:release:check {--probe : Probe database, cache, object storage, queue worker, and scheduler} {--json : Emit machine-readable JSON}';

    protected $description = 'Fail unless Chef is configured for a production release';

    public function handle(ReleaseReadiness $release, ReleaseRuntimeProbe $runtime): int
    {
        $failures = $release->configurationFailures();
        $probes = null;

        if ($this->option('probe')) {
            $readiness = $runtime->inspect();
            $probes = $readiness['components'];

            foreach ($readiness['failures'] as $component) {
                $failures[] = [
                    'key' => "probe.{$component}",
                    'message' => str($component)->replace('_', ' ')->ucfirst().' probe failed.',
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $failures === [],
                'release' => config('app.release'),
                'probes' => $probes,
                'failures' => $failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($failures === []) {
            $this->info('Chef release configuration is ready.');
        } else {
            $this->error('Chef release configuration is not ready.');

            foreach ($failures as $failure) {
                $this->line(" - {$failure['key']}: {$failure['message']}");
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
