<?php

namespace App\Support;

use App\Jobs\RecordReleaseQueueProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReleaseRuntimeProbe
{
    public function __construct(private readonly ApplicationReadiness $application) {}

    /**
     * @return array{ready: bool, components: array{database: bool, cache: bool, object_storage: bool, queue_worker: bool, scheduler: bool}, failures: array<int, string>}
     */
    public function inspect(): array
    {
        $applicationReadiness = $this->application->inspect();
        $applicationFailures = $applicationReadiness['failures'];
        $components = [
            'database' => ! in_array('database', $applicationFailures, true),
            'cache' => ! in_array('cache', $applicationFailures, true),
            'object_storage' => $this->probeObjectStorage(),
            'queue_worker' => $this->probeQueueWorker(),
            'scheduler' => $this->schedulerHeartbeatIsFresh(),
        ];

        $failures = array_keys(array_filter($components, fn (bool $ready): bool => ! $ready));

        return [
            'ready' => $failures === [],
            'components' => $components,
            'failures' => $failures,
        ];
    }

    private function probeObjectStorage(): bool
    {
        $path = '_health/release-'.str()->uuid().'.txt';
        $disk = null;

        try {
            $disk = Storage::disk((string) config('filesystems.default'));
            $stored = $disk->put($path, 'chef-release-probe');
            $readable = $stored && $disk->get($path) === 'chef-release-probe';
            $deleted = $disk->delete($path);

            return $readable && $deleted;
        } catch (Throwable) {
            if ($disk !== null) {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                    // The failed probe is already reported without exposing storage details.
                }
            }

            return false;
        }
    }

    private function probeQueueWorker(): bool
    {
        $token = (string) str()->uuid();
        $key = 'chef:release:queue-probe:'.$token;
        $timeoutSeconds = max(1, (int) config('chef.release.queue_probe_timeout_seconds'));

        try {
            RecordReleaseQueueProbe::dispatch($token);
            $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);

            do {
                if (Cache::pull($key) === $token) {
                    return true;
                }

                usleep(100_000);
            } while (hrtime(true) < $deadline);
        } catch (Throwable) {
            // Runtime failures are returned as a component status, not leaked.
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // A cache outage is already represented by failed cache and queue probes.
            }
        }

        return false;
    }

    private function schedulerHeartbeatIsFresh(): bool
    {
        try {
            $heartbeat = Cache::get('chef:release:scheduler-heartbeat');

            if (! is_string($heartbeat)) {
                return false;
            }

            $recordedAt = Carbon::parse($heartbeat);
            $maxAge = max(60, (int) config('chef.release.scheduler_heartbeat_max_age_seconds'));

            return $recordedAt->betweenIncluded(now()->subSeconds($maxAge), now()->addSeconds(30));
        } catch (Throwable) {
            return false;
        }
    }
}
