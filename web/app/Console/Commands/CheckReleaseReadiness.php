<?php

namespace App\Console\Commands;

use App\Support\ApplicationReadiness;
use App\Support\ReleaseReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CheckReleaseReadiness extends Command
{
    protected $signature = 'chef:release:check {--probe : Probe the configured database, cache, and object storage} {--json : Emit machine-readable JSON}';

    protected $description = 'Fail unless Chef is configured for a production release';

    public function handle(ReleaseReadiness $release, ApplicationReadiness $application): int
    {
        $failures = $release->configurationFailures();

        if ($this->option('probe')) {
            $readiness = $application->inspect();

            foreach ($readiness['failures'] as $component) {
                $failures[] = [
                    'key' => "probe.{$component}",
                    'message' => ucfirst($component).' probe failed.',
                ];
            }

            try {
                $path = '_health/release-'.str()->uuid().'.txt';
                $disk = Storage::disk((string) config('filesystems.default'));
                $stored = $disk->put($path, 'chef-release-probe');
                $readable = $stored && $disk->get($path) === 'chef-release-probe';
                $disk->delete($path);

                if (! $readable) {
                    throw new \RuntimeException('Object storage did not return the probe.');
                }
            } catch (Throwable) {
                $failures[] = ['key' => 'probe.object_storage', 'message' => 'Object storage write/read/delete probe failed.'];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $failures === [],
                'release' => config('app.release'),
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
