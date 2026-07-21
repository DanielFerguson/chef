import { chmod, mkdir, unlink } from 'node:fs/promises';
import { createServer } from 'node:net';
import type { Socket } from 'node:net';
import { dirname } from 'node:path';

import {
    connectRemoteBrowser,
    executeCommand,
    WorkerFailure,
} from './worker.js';
import type { CommandEnvelope } from './worker.js';

const actorProtocolVersion = 'chef.browser.actor.v1' as const;

type ActorRequest = {
    version: typeof actorProtocolVersion;
    actor_id: string;
    fencing_token: string;
    generation: number;
    command_id: string;
    command: CommandEnvelope;
};

type ActorResponse = {
    version: typeof actorProtocolVersion;
    actor_id: string;
    generation: number;
    command_id: string;
    ok: boolean;
    payload?: Record<string, unknown>;
    error_code?: string;
    error_message?: string;
    diagnostics: Record<string, unknown>;
};

function requiredEnvironment(name: string): string {
    const value = process.env[name];

    if (!value) {
        throw new Error(`Missing ${name}.`);
    }

    return value;
}

async function main(): Promise<void> {
    const socketPath = requiredEnvironment('CHEF_BROWSER_ACTOR_SOCKET');
    const cdpUrl = requiredEnvironment('CHEF_BROWSER_CDP_URL');
    const actorId = requiredEnvironment('CHEF_BROWSER_ACTOR_ID');
    const fencingToken = requiredEnvironment('CHEF_BROWSER_FENCING_TOKEN');
    const generation = Number(
        requiredEnvironment('CHEF_BROWSER_ACTOR_GENERATION'),
    );
    const expiresAt = Date.parse(
        requiredEnvironment('CHEF_BROWSER_ACTOR_EXPIRES_AT'),
    );

    if (!Number.isInteger(generation) || generation < 1) {
        throw new Error('Invalid actor generation.');
    }

    await mkdir(dirname(socketPath), { recursive: true });
    await unlink(socketPath).catch(() => undefined);

    const actorStartedAt = new Date();
    const connection = await connectRemoteBrowser(cdpUrl);
    const actorConnectedAt = new Date();
    let controlMode: 'agent' | 'human' = 'agent';
    let commandCount = 0;
    let commandQueue = Promise.resolve();
    let shuttingDown = false;

    const server = createServer((socket) => {
        let input = '';

        socket.setEncoding('utf8');
        socket.setTimeout(60_000, () => socket.destroy());
        socket.on('data', (chunk) => {
            input += chunk;

            if (input.length > 1_000_000) {
                socket.destroy();

                return;
            }

            const newline = input.indexOf('\n');

            if (newline < 0) {
                return;
            }

            const line = input.slice(0, newline);
            input = '';

            commandQueue = commandQueue
                .then(() => handleRequest(socket, line))
                .catch(() => {
                    socket.destroy();
                });
        });
    });

    async function handleRequest(socket: Socket, line: string): Promise<void> {
        const commandStartedAt = performance.now();
        let request: ActorRequest | null = null;

        try {
            const value: unknown = JSON.parse(line);

            if (!isActorRequest(value)) {
                throw new WorkerFailure(
                    'actor_protocol_mismatch',
                    'The browser actor request did not match the required protocol.',
                );
            }

            request = value;

            if (
                request.actor_id !== actorId ||
                request.generation !== generation ||
                request.fencing_token !== fencingToken
            ) {
                throw new WorkerFailure(
                    'actor_fenced',
                    'This browser actor no longer owns the session.',
                );
            }

            let payload: Record<string, unknown>;

            switch (request.command.type) {
                case 'ping':
                    payload = { control_mode: controlMode };
                    break;
                case 'yield_control':
                    controlMode = 'human';
                    payload = { control_mode: controlMode };
                    break;
                case 'resume_control':
                    controlMode = 'agent';
                    payload = { control_mode: controlMode };
                    break;
                case 'shutdown':
                    shuttingDown = true;
                    payload = { stopped: true };
                    break;
                default:
                    if (controlMode !== 'agent') {
                        throw new WorkerFailure(
                            'exclusive_human_control',
                            'The browser actor yielded exclusive control to the person.',
                        );
                    }

                    payload = await executeCommand(
                        connection.page,
                        request.command,
                    );
                    break;
            }

            commandCount++;
            writeResponse(socket, {
                version: actorProtocolVersion,
                actor_id: actorId,
                generation,
                command_id: request.command_id,
                ok: true,
                payload,
                diagnostics: diagnostics(commandStartedAt),
            });
        } catch (error) {
            const failure =
                error instanceof WorkerFailure
                    ? error
                    : error instanceof Error && error.name === 'TimeoutError'
                      ? new WorkerFailure(
                            'worker_timeout',
                            'Woolworths did not expose a verifiable control in time.',
                        )
                      : new WorkerFailure(
                            'actor_failure',
                            'The browser actor did not reach a safe checkpoint.',
                        );
            writeResponse(socket, {
                version: actorProtocolVersion,
                actor_id: actorId,
                generation,
                command_id: request?.command_id ?? 'invalid',
                ok: false,
                error_code: failure.code,
                error_message: failure.safeMessage,
                diagnostics: diagnostics(commandStartedAt),
            });
        }

        if (shuttingDown) {
            server.close();
            await unlink(socketPath).catch(() => undefined);
            setTimeout(() => process.exit(0), 25).unref();
        }
    }

    function diagnostics(commandStartedAt: number): Record<string, unknown> {
        return {
            actor_pid: process.pid,
            actor_started_at: actorStartedAt.toISOString(),
            actor_connected_at: actorConnectedAt.toISOString(),
            heartbeat_at: new Date().toISOString(),
            cdp_connect_ms: connection.connect_ms,
            command_ms:
                Math.round((performance.now() - commandStartedAt) * 100) / 100,
            command_count: commandCount,
            control_mode: controlMode,
        };
    }

    server.listen(socketPath, async () => {
        await chmod(socketPath, 0o600);
    });

    const expiryTimer = setTimeout(
        () => {
            server.close();
            void unlink(socketPath).finally(() => process.exit(0));
        },
        Math.max(1_000, expiresAt - Date.now()),
    );
    expiryTimer.unref();

    const stop = (): void => {
        server.close();
        void unlink(socketPath).finally(() => process.exit(0));
    };
    process.once('SIGTERM', stop);
    process.once('SIGINT', stop);
}

function writeResponse(socket: Socket, response: ActorResponse): void {
    socket.end(`${JSON.stringify(response)}\n`);
}

function isActorRequest(value: unknown): value is ActorRequest {
    if (!isRecord(value) || value.version !== actorProtocolVersion) {
        return false;
    }

    return (
        typeof value.actor_id === 'string' &&
        typeof value.fencing_token === 'string' &&
        typeof value.generation === 'number' &&
        typeof value.command_id === 'string' &&
        isRecord(value.command) &&
        value.command.version === 'chef.browser.v1' &&
        typeof value.command.type === 'string'
    );
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

void main().catch(() => process.exit(1));
