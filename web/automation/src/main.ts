import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';

import {
    WorkerFailure,
    parseCommandRequest,
    protocolVersion,
} from './protocol.js';
import type { CommandRequest, CommandResponse } from './protocol.js';
import { executeTool, isFixtureMode, resolveCdpUrl } from './tools.js';

async function readCommand(): Promise<CommandRequest> {
    const argvPayload = process.argv[2];

    if (typeof argvPayload === 'string' && argvPayload.trim() !== '') {
        let value: unknown;

        try {
            value = JSON.parse(argvPayload);
        } catch {
            throw new WorkerFailure(
                'invalid_json',
                'The Stagehand retailer worker command was not valid JSON.',
            );
        }

        return parseCommandRequest(value);
    }

    const input = createInterface({
        input: process.stdin,
        crlfDelay: Infinity,
    });

    for await (const line of input) {
        if (!line.trim()) {
            continue;
        }

        let value: unknown;

        try {
            value = JSON.parse(line);
        } catch {
            throw new WorkerFailure(
                'invalid_json',
                'The Stagehand retailer worker command was not valid JSON.',
            );
        }

        return parseCommandRequest(value);
    }

    throw new WorkerFailure(
        'missing_command',
        'The Stagehand retailer worker did not receive a command.',
    );
}

function respond(response: CommandResponse): Promise<void> {
    return new Promise((resolve, reject) => {
        process.stdout.write(`${JSON.stringify(response)}\n`, (error) => {
            if (error) {
                reject(error);

                return;
            }

            resolve();
        });
    });
}

async function main(): Promise<void> {
    let exitCode = 0;

    try {
        const command = await readCommand();
        const payload = await executeTool(
            command.command,
            {
                cdpUrl: resolveCdpUrl(),
                fixtureMode: isFixtureMode(),
            },
            command.payload ?? {},
        );

        await respond({
            version: protocolVersion,
            ok: true,
            payload,
            diagnostics: {
                command: command.command,
            },
        });
    } catch (error) {
        exitCode = 1;
        const failure =
            error instanceof WorkerFailure
                ? error
                : new WorkerFailure(
                      'worker_failure',
                      'The Stagehand retailer worker stopped before it could finish.',
                  );

        await respond({
            version: protocolVersion,
            ok: false,
            error_code: failure.code,
            error_message: failure.safeMessage,
        });
    }

    process.exit(exitCode);
}

const invokedPath = process.argv[1];

if (invokedPath && import.meta.url === pathToFileURL(invokedPath).href) {
    void main();
}
