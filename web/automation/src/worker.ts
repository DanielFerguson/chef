import process from 'node:process';

import Browserbase from '@browserbasehq/sdk';
import { Stagehand } from '@browserbasehq/stagehand';

import { executeColesCommand } from './coles.js';
import {
    commandResult,
    parseWorkerRequest,
    RETAILER_PROTOCOL,
    WorkerProtocolError,
} from './protocol.js';
import type { WorkerRequest } from './protocol.js';

const apiKey = process.env.BROWSERBASE_API_KEY ?? '';
const projectId = process.env.BROWSERBASE_PROJECT_ID ?? '';
const region = normalizeRegion(process.env.BROWSERBASE_REGION);
const proxyCountry = process.env.BROWSERBASE_PROXY_COUNTRY ?? 'AU';
const sessionTimeoutSeconds = normalizeSessionTimeout(
    process.env.BROWSERBASE_SESSION_TIMEOUT_SECONDS,
);

await main();

async function main(): Promise<void> {
    try {
        const request = parseWorkerRequest(
            JSON.parse(await readStandardInput()) as unknown,
        );
        const response = await handleRequest(request);

        process.stdout.write(JSON.stringify(response));
    } catch (error) {
        const reason =
            error instanceof WorkerProtocolError
                ? error.message
                : 'worker_unavailable';

        process.stdout.write(
            JSON.stringify(commandResult('failed', {}, reason)),
        );
        process.exitCode = 1;
    }
}

async function handleRequest(
    request: WorkerRequest,
): Promise<Record<string, unknown>> {
    const browserbase = browserbaseClient();

    if (request.operation === 'context.create') {
        const context = await browserbase.contexts.create(
            projectId === '' ? {} : { projectId },
        );

        return {
            protocol: RETAILER_PROTOCOL,
            context_id: context.id,
        };
    }

    if (request.operation === 'context.delete') {
        await browserbase.contexts.delete(request.context_id);

        return {
            protocol: RETAILER_PROTOCOL,
            deleted: true,
        };
    }

    if (request.operation === 'session.start') {
        const session = await createSession(
            browserbase,
            request.context_id,
            true,
            {
                purpose: request.purpose,
            },
        );
        const live = await browserbase.sessions.debug(session.id);

        return {
            protocol: RETAILER_PROTOCOL,
            session_id: session.id,
            live_view_url: live.debuggerFullscreenUrl,
            expires_at: session.expiresAt,
        };
    }

    if (request.operation === 'session.input') {
        return relaySessionInput(request.session_id, request.input);
    }

    if (request.command === 'release_session' && request.session_id !== null) {
        await releaseSession(browserbase, request.session_id);

        return commandResult('succeeded', { released: true });
    }

    const ownsSession = request.session_id === null;
    const session =
        request.session_id ??
        (
            await createSession(browserbase, request.context_id, false, {
                command: request.command,
            })
        ).id;
    const stagehand = new Stagehand({
        env: 'BROWSERBASE',
        apiKey,
        projectId: projectId === '' ? undefined : projectId,
        browserbaseSessionID: session,
        keepAlive: true,
        disablePino: true,
        verbose: 0,
        serverCache: false,
        logger: () => undefined,
    });

    try {
        await stagehand.init();

        return await executeColesCommand(
            stagehand,
            request.command,
            request.payload,
        );
    } finally {
        await stagehand.close().catch(() => undefined);

        if (ownsSession) {
            await releaseSession(browserbase, session).catch(() => undefined);
        }
    }
}

async function relaySessionInput(
    sessionId: string,
    input: { kind: 'text' | 'key'; value: string },
): Promise<Record<string, unknown>> {
    const stagehand = new Stagehand({
        env: 'BROWSERBASE',
        apiKey,
        projectId: projectId === '' ? undefined : projectId,
        browserbaseSessionID: sessionId,
        keepAlive: true,
        disablePino: true,
        verbose: 0,
        serverCache: false,
        logger: () => undefined,
    });

    try {
        await stagehand.init();
        const page = stagehand.context.pages()[0];

        if (page === undefined) {
            return commandResult('failed', {}, 'page_unavailable');
        }

        if (input.kind === 'text') {
            await page.type(input.value);
        } else {
            await page.keyPress(input.value);
        }

        return commandResult('succeeded', {
            forwarded: true,
            kind: input.kind,
        });
    } catch {
        return commandResult('retryable', {}, 'live_input_unavailable');
    } finally {
        await stagehand.close().catch(() => undefined);
    }
}

function browserbaseClient(): Browserbase {
    if (apiKey === '') {
        throw new Error('missing_browserbase_api_key');
    }

    return new Browserbase({
        apiKey,
        timeout: 60_000,
        maxRetries: 1,
    });
}

async function createSession(
    browserbase: Browserbase,
    contextId: string,
    keepAlive: boolean,
    metadata: Record<string, string>,
) {
    return browserbase.sessions.create({
        projectId: projectId === '' ? undefined : projectId,
        keepAlive,
        region,
        timeout: sessionTimeoutSeconds,
        proxies: [
            {
                type: 'browserbase',
                geolocation: {
                    country: proxyCountry,
                },
            },
        ],
        browserSettings: {
            context: {
                id: contextId,
                persist: true,
            },
            allowedDomains: ['coles.com.au'],
            logSession: false,
            recordSession: false,
            solveCaptchas: false,
        },
        userMetadata: {
            protocol: RETAILER_PROTOCOL,
            ...metadata,
        },
    });
}

async function releaseSession(
    browserbase: Browserbase,
    sessionId: string,
): Promise<void> {
    const session = await browserbase.sessions.retrieve(sessionId);

    if (session.status === 'PENDING' || session.status === 'RUNNING') {
        await browserbase.sessions.update(sessionId, {
            status: 'REQUEST_RELEASE',
            projectId: projectId === '' ? undefined : projectId,
        });
    }
}

function normalizeRegion(
    value: string | undefined,
): 'us-west-2' | 'us-east-1' | 'eu-central-1' | 'ap-southeast-1' {
    if (
        value === 'us-west-2' ||
        value === 'us-east-1' ||
        value === 'eu-central-1' ||
        value === 'ap-southeast-1'
    ) {
        return value;
    }

    return 'ap-southeast-1';
}

function normalizeSessionTimeout(value: string | undefined): number {
    const timeout = Number.parseInt(value ?? '', 10);

    return Number.isFinite(timeout) && timeout >= 60 && timeout <= 3600
        ? timeout
        : 300;
}

async function readStandardInput(): Promise<string> {
    const chunks: Buffer[] = [];

    for await (const chunk of process.stdin) {
        chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
    }

    return Buffer.concat(chunks).toString('utf8');
}
