export const protocolVersion = 'chef.retailer.stagehand.v1' as const;

export const supportedCommands = [
    'probe_auth',
    'inspect_cart',
    'clear_cart',
    'add_product',
] as const;

export type StagehandCommand = (typeof supportedCommands)[number];

export type CommandRequest = {
    version: typeof protocolVersion;
    command: StagehandCommand;
    payload?: Record<string, unknown>;
};

export type CommandResponse = {
    version: typeof protocolVersion;
    ok: boolean;
    payload?: Record<string, unknown>;
    error_code?: string;
    error_message?: string;
    diagnostics?: Record<string, unknown>;
};

export class WorkerFailure extends Error {
    constructor(
        public readonly code: string,
        public readonly safeMessage: string,
    ) {
        super(safeMessage);
    }
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isStagehandCommand(value: unknown): value is StagehandCommand {
    return typeof value === 'string' && (supportedCommands as readonly string[]).includes(value);
}

export function parseCommandRequest(value: unknown): CommandRequest {
    if (!isRecord(value) || value.version !== protocolVersion) {
        throw new WorkerFailure(
            'protocol_mismatch',
            'The Stagehand retailer worker protocol version did not match.',
        );
    }

    if (!isStagehandCommand(value.command)) {
        throw new WorkerFailure(
            'unknown_command',
            'The Stagehand retailer worker does not support that command yet.',
        );
    }

    return {
        version: protocolVersion,
        command: value.command,
        payload: isRecord(value.payload) ? value.payload : {},
    };
}
