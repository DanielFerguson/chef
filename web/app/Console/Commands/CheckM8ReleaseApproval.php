<?php

namespace App\Console\Commands;

use App\Support\M8LaunchEvidence;
use App\Support\ReleaseReadiness;
use App\Support\ReleaseRuntimeProbe;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class CheckM8ReleaseApproval extends Command
{
    protected $signature = 'chef:release:approve {manifest : Path to signed M8 evidence JSON} {--json : Emit machine-readable JSON}';

    protected $description = 'Fail unless the deployed release and every M8 launch gate are proven';

    public function handle(ReleaseReadiness $release, ReleaseRuntimeProbe $runtime, M8LaunchEvidence $evidence): int
    {
        $failures = $release->configurationFailures();
        $readiness = $runtime->inspect();

        foreach ($readiness['failures'] as $component) {
            $failures[] = [
                'key' => "probe.{$component}",
                'message' => str($component)->replace('_', ' ')->ucfirst().' probe failed.',
            ];
        }

        try {
            $manifest = $this->readManifest((string) $this->argument('manifest'));
            $failures = [...$failures, ...$evidence->failures($manifest)];
        } catch (Throwable $exception) {
            $failures[] = ['key' => 'evidence.manifest', 'message' => $exception->getMessage()];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'eligible_to_tag' => $failures === [],
                'release' => config('app.release'),
                'probes' => $readiness['components'],
                'failures' => $failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($failures === []) {
            $this->info('Chef M8 evidence is complete. This exact deployment is eligible for the version 1 tag.');
        } else {
            $this->error('Chef M8 release approval failed.');

            foreach ($failures as $failure) {
                $this->line(" - {$failure['key']}: {$failure['message']}");
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException('Evidence manifest could not be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Evidence manifest could not be read.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Evidence manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new \RuntimeException('Evidence manifest must be a JSON object.');
        }

        return $manifest;
    }
}
