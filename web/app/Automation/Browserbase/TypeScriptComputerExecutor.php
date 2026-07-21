<?php

namespace App\Automation\Browserbase;

use App\Automation\Contracts\BrowserSessionProvider;
use App\Automation\Contracts\ComputerExecutor;
use App\Automation\Data\WorkerCommand;
use App\Automation\Data\WorkerResult;
use App\Automation\Exceptions\ActorRecoveryReconciliationRequired;
use App\Automation\Exceptions\BrowserActorUnavailableException;
use App\Enums\BrowserActorStatus;
use App\Enums\BrowserSessionStatus;
use App\Models\BrowserActor;
use App\Models\BrowserSession;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TypeScriptComputerExecutor implements ComputerExecutor
{
    private const ACTOR_PROTOCOL = 'chef.browser.actor.v1';

    /** @var array<int, string> */
    private const MUTATING_COMMANDS = [
        'clear_cart',
        'execute_action',
        'prepare_item',
        'prepare_and_verify_item',
    ];

    public function __construct(private readonly BrowserSessionProvider $sessions) {}

    public function execute(BrowserSession $session, WorkerCommand $command): WorkerResult
    {
        $actor = $this->ensureActor($session);

        try {
            return $this->send($actor, $command);
        } catch (BrowserActorUnavailableException) {
            $actor = $this->recover($session, $actor);

            if (in_array($command->type, self::MUTATING_COMMANDS, true)) {
                throw new ActorRecoveryReconciliationRequired(
                    'The browser actor recovered and the real cart must be reconciled before mutation resumes.',
                );
            }

            $result = $this->send($actor, $command);

            return new WorkerResult(
                ok: $result->ok,
                payload: $result->payload,
                errorCode: $result->errorCode,
                errorMessage: $result->errorMessage,
                diagnostics: [...$result->diagnostics, 'actor_recovered' => true],
            );
        }
    }

    public function heartbeat(BrowserSession $session): WorkerResult
    {
        return $this->execute($session, new WorkerCommand('ping'));
    }

    public function yieldControl(BrowserSession $session): void
    {
        $this->requireSuccess($this->execute($session, new WorkerCommand('yield_control')));
        $session->latestActor?->update(['status' => BrowserActorStatus::HumanControl]);
    }

    public function resumeControl(BrowserSession $session): void
    {
        $this->requireSuccess($this->execute($session, new WorkerCommand('resume_control')));
        $session->latestActor?->update(['status' => BrowserActorStatus::Ready]);
    }

    public function stop(BrowserSession $session): void
    {
        $actor = $session->actors()->whereIn('status', $this->activeStatuses())->latest('generation')->first();

        if ($actor === null) {
            return;
        }

        try {
            $this->send($actor, new WorkerCommand('shutdown'));
        } catch (Throwable) {
            $this->terminateProcess($actor);
        }

        $actor->update([
            'status' => BrowserActorStatus::Stopped,
            'stopped_at' => now(),
        ]);
        $this->removeSocket($actor);
    }

    private function ensureActor(BrowserSession $session): BrowserActor
    {
        $actor = $session->actors()->whereIn('status', $this->activeStatuses())->latest('generation')->first();

        return $actor ?? $this->start($session);
    }

    private function recover(BrowserSession $session, BrowserActor $actor): BrowserActor
    {
        $actor->update([
            'status' => BrowserActorStatus::Lost,
            'stopped_at' => now(),
            'diagnostics' => [
                ...($actor->diagnostics ?? []),
                'lost_at' => now()->toIso8601String(),
            ],
        ]);
        $this->terminateProcess($actor);
        $this->removeSocket($actor);

        return $this->start($session, recovering: true);
    }

    private function start(BrowserSession $session, bool $recovering = false): BrowserActor
    {
        try {
            return Cache::lock('chef-browser-actor:'.$session->id, 30)->block(5, function () use ($session, $recovering): BrowserActor {
                $session = $session->refresh();
                $existing = $session->actors()->whereIn('status', $this->activeStatuses())->latest('generation')->first();

                if ($existing !== null) {
                    return $existing;
                }

                $actor = DB::transaction(function () use ($session, $recovering): BrowserActor {
                    $generation = ((int) $session->actors()->lockForUpdate()->max('generation')) + 1;
                    $actorUuid = (string) Str::uuid();

                    return BrowserActor::query()->create([
                        'team_id' => $session->team_id,
                        'browser_session_id' => $session->id,
                        'generation' => $generation,
                        'actor_uuid' => $actorUuid,
                        'fencing_token' => Str::random(64),
                        'socket_path' => sys_get_temp_dir().'/chef-actor-'.substr(hash('sha256', $actorUuid), 0, 24).'.sock',
                        'status' => $recovering ? BrowserActorStatus::Recovering : BrowserActorStatus::Starting,
                        'started_at' => now(),
                        'diagnostics' => ['recovery_start' => $recovering],
                    ]);
                });

                return $this->launch($session, $actor);
            });
        } catch (LockTimeoutException) {
            throw new BrowserActorUnavailableException('The browser actor ownership lock timed out.');
        }
    }

    private function launch(BrowserSession $session, BrowserActor $actor): BrowserActor
    {
        $startedAt = microtime(true);
        $connectionUrlStartedAt = microtime(true);
        $connectionUrl = $this->sessions->connectionUrl($session);
        $connectionUrlMilliseconds = round((microtime(true) - $connectionUrlStartedAt) * 1000, 2);
        $launcherPath = (string) config('services.chef_automation.actor_launcher_path', base_path('automation/dist/launch-actor.js'));

        if (! is_file($launcherPath)) {
            $actor->update(['status' => BrowserActorStatus::Lost, 'stopped_at' => now()]);

            throw new RuntimeException('The compiled browser actor launcher is missing.');
        }

        $process = new Process([
            (string) config('services.chef_automation.node_binary', 'node'),
            $launcherPath,
        ], base_path(), [
            'CHEF_BROWSER_ACTOR_SOCKET' => $actor->socket_path,
            'CHEF_BROWSER_CDP_URL' => $connectionUrl,
            'CHEF_BROWSER_ACTOR_ID' => $actor->actor_uuid,
            'CHEF_BROWSER_FENCING_TOKEN' => $actor->fencing_token,
            'CHEF_BROWSER_ACTOR_GENERATION' => (string) $actor->generation,
            'CHEF_BROWSER_ACTOR_EXPIRES_AT' => ($session->expires_at ?? now()->addMinutes(15))->toIso8601String(),
            'CHEF_BROWSER_ACTOR_ENTRYPOINT' => (string) config('services.chef_automation.actor_path', base_path('automation/dist/actor.js')),
        ]);
        $startupTimeout = max(1, (int) config('services.chef_automation.actor_startup_timeout', 12));
        $process->setTimeout((float) $startupTimeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $actor->update(['status' => BrowserActorStatus::Lost, 'stopped_at' => now()]);

            throw new BrowserActorUnavailableException('The browser actor launcher stopped before startup.');
        }

        try {
            $launch = json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $launch = null;
        }

        if (! is_array($launch) || ! is_int($launch['pid'] ?? null)) {
            $actor->update(['status' => BrowserActorStatus::Lost, 'stopped_at' => now()]);

            throw new BrowserActorUnavailableException('The browser actor launcher returned an invalid process identifier.');
        }

        $actor->update(['process_id' => $launch['pid']]);
        $deadline = microtime(true) + $startupTimeout;
        $lastFailure = null;

        do {
            try {
                $result = $this->send($actor->refresh(), new WorkerCommand('ping'));

                if ($result->ok) {
                    if ($session->status === BrowserSessionStatus::HumanControl) {
                        $this->requireSuccess($this->send($actor->refresh(), new WorkerCommand('yield_control')));
                    }

                    $actor->refresh()->update([
                        'status' => $session->status === BrowserSessionStatus::HumanControl
                            ? BrowserActorStatus::HumanControl
                            : BrowserActorStatus::Ready,
                        'connected_at' => now(),
                        'diagnostics' => [
                            ...($actor->diagnostics ?? []),
                            ...$result->diagnostics,
                            'connection_url_ms' => $connectionUrlMilliseconds,
                            'actor_startup_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                        ],
                    ]);

                    return $actor->refresh();
                }
            } catch (BrowserActorUnavailableException $exception) {
                $lastFailure = $exception;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $actor->update(['status' => BrowserActorStatus::Lost, 'stopped_at' => now()]);
        $this->terminateProcess($actor);
        $this->removeSocket($actor);

        throw new BrowserActorUnavailableException(
            'The browser actor did not establish its session connection in time.',
            previous: $lastFailure,
        );
    }

    private function send(BrowserActor $actor, WorkerCommand $command): WorkerResult
    {
        $timeout = $command->type === 'probe_authentication'
            ? min(
                max(1, (int) config('services.chef_automation.actor_rpc_timeout', 50)),
                max(1, (int) config('services.chef_automation.authentication_worker_timeout', 20)),
            )
            : max(1, (int) config('services.chef_automation.actor_rpc_timeout', 50));
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'unix://'.$actor->socket_path,
            $errorCode,
            $errorMessage,
            min(2, $timeout),
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            throw new BrowserActorUnavailableException('The browser actor socket was unavailable.');
        }

        stream_set_timeout($socket, $timeout);
        $commandId = (string) Str::uuid();
        $request = json_encode([
            'version' => self::ACTOR_PROTOCOL,
            'actor_id' => $actor->actor_uuid,
            'fencing_token' => $actor->fencing_token,
            'generation' => $actor->generation,
            'command_id' => $commandId,
            'command' => $command->toArray(),
        ], JSON_THROW_ON_ERROR)."\n";

        if (fwrite($socket, $request) !== strlen($request)) {
            fclose($socket);

            throw new BrowserActorUnavailableException('The browser actor command could not be delivered.');
        }

        $response = '';
        while (! feof($socket)) {
            $chunk = fgets($socket);

            if ($chunk === false) {
                break;
            }

            $response .= $chunk;
            if (str_contains($response, "\n")) {
                break;
            }

            if (strlen($response) > 12_000_000) {
                fclose($socket);

                throw new BrowserActorUnavailableException('The browser actor response exceeded the safe size limit.');
            }
        }
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        if ($metadata['timed_out'] || trim($response) === '') {
            throw new BrowserActorUnavailableException('The browser actor did not answer before the command deadline.');
        }

        try {
            $payload = json_decode(trim($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BrowserActorUnavailableException('The browser actor returned invalid JSON.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::ACTOR_PROTOCOL
            || ($payload['actor_id'] ?? null) !== $actor->actor_uuid
            || ($payload['generation'] ?? null) !== $actor->generation
            || ($payload['command_id'] ?? null) !== $commandId) {
            throw new BrowserActorUnavailableException('The browser actor response did not match its fenced request.');
        }

        $diagnostics = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
        $actor->update([
            'heartbeat_at' => now(),
            'diagnostics' => [...($actor->diagnostics ?? []), ...$diagnostics],
        ]);

        return new WorkerResult(
            ok: (bool) ($payload['ok'] ?? false),
            payload: is_array($payload['payload'] ?? null) ? $payload['payload'] : [],
            errorCode: is_string($payload['error_code'] ?? null) ? $payload['error_code'] : null,
            errorMessage: is_string($payload['error_message'] ?? null) ? $payload['error_message'] : null,
            diagnostics: $diagnostics,
        );
    }

    private function requireSuccess(WorkerResult $result): void
    {
        if (! $result->ok) {
            throw new RuntimeException($result->errorMessage ?? 'The browser actor rejected the lifecycle command.');
        }
    }

    /** @return array<int, string> */
    private function activeStatuses(): array
    {
        return collect(BrowserActorStatus::cases())
            ->filter->isActive()
            ->map->value
            ->all();
    }

    private function terminateProcess(BrowserActor $actor): void
    {
        if ($actor->process_id !== null && function_exists('posix_kill')) {
            @posix_kill($actor->process_id, SIGTERM);
        }
    }

    private function removeSocket(BrowserActor $actor): void
    {
        if (is_file($actor->socket_path)) {
            @unlink($actor->socket_path);
        }
    }
}
