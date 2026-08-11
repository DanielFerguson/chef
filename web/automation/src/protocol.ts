import { createHash } from 'node:crypto';

export const RETAILER_PROTOCOL = 'chef.retailer.v1' as const;

export const RETAILER_COMMANDS = [
    'probe_auth',
    'search_products',
    'inspect_basket',
    'ensure_basket_empty',
    'ensure_basket_line',
    'release_session',
] as const;

export type RetailerCommand = (typeof RETAILER_COMMANDS)[number];
export type WorkerStatus =
    'succeeded' | 'blocked' | 'retryable' | 'uncertain' | 'failed';

type WorkerRequestBase = {
    protocol: typeof RETAILER_PROTOCOL;
};

export type WorkerRequest = WorkerRequestBase &
    (
        | { operation: 'context.create' }
        | { operation: 'context.delete'; context_id: string }
        | {
              operation: 'session.start';
              context_id: string;
              purpose: 'authentication' | 'review';
          }
        | {
              operation: 'session.input';
              context_id: string;
              session_id: string;
              input:
                  | { kind: 'text'; value: string }
                  | { kind: 'key'; value: string };
          }
        | {
              operation: 'command.execute';
              context_id: string;
              session_id: string | null;
              command: RetailerCommand;
              payload: Record<string, unknown>;
          }
    );

export type WorkerCommandResult = {
    protocol: typeof RETAILER_PROTOCOL;
    status: WorkerStatus;
    reason_code: string | null;
    verification_checksum: string | null;
    data: Record<string, unknown>;
};

export class WorkerProtocolError extends Error {}

export function parseWorkerRequest(input: unknown): WorkerRequest {
    if (!isRecord(input) || input.protocol !== RETAILER_PROTOCOL) {
        throw new WorkerProtocolError('unsupported_protocol');
    }

    if (input.operation === 'context.create') {
        return {
            protocol: RETAILER_PROTOCOL,
            operation: 'context.create',
        };
    }

    if (input.operation === 'context.delete') {
        return {
            protocol: RETAILER_PROTOCOL,
            operation: 'context.delete',
            context_id: requiredIdentifier(input.context_id, 'context_id'),
        };
    }

    if (input.operation === 'session.start') {
        if (input.purpose !== 'authentication' && input.purpose !== 'review') {
            throw new WorkerProtocolError('invalid_session_purpose');
        }

        return {
            protocol: RETAILER_PROTOCOL,
            operation: 'session.start',
            context_id: requiredIdentifier(input.context_id, 'context_id'),
            purpose: input.purpose,
        };
    }

    if (input.operation === 'session.input') {
        if (!isRecord(input.input)) {
            throw new WorkerProtocolError('invalid_session_input');
        }

        const kind = input.input.kind;
        const value = input.input.value;

        if (
            (kind !== 'text' && kind !== 'key') ||
            typeof value !== 'string' ||
            value.length === 0 ||
            value.length > 256
        ) {
            throw new WorkerProtocolError('invalid_session_input');
        }

        return {
            protocol: RETAILER_PROTOCOL,
            operation: 'session.input',
            context_id: requiredIdentifier(input.context_id, 'context_id'),
            session_id: requiredIdentifier(input.session_id, 'session_id'),
            input: { kind, value },
        };
    }

    if (input.operation === 'command.execute') {
        if (
            typeof input.command !== 'string' ||
            !RETAILER_COMMANDS.includes(input.command as RetailerCommand)
        ) {
            throw new WorkerProtocolError('invalid_command');
        }

        if (!isRecord(input.payload)) {
            throw new WorkerProtocolError('invalid_payload');
        }

        return {
            protocol: RETAILER_PROTOCOL,
            operation: 'command.execute',
            context_id: requiredIdentifier(input.context_id, 'context_id'),
            session_id:
                input.session_id === null || input.session_id === undefined
                    ? null
                    : requiredIdentifier(input.session_id, 'session_id'),
            command: input.command as RetailerCommand,
            payload: input.payload,
        };
    }

    throw new WorkerProtocolError('invalid_operation');
}

export function commandResult(
    status: WorkerStatus,
    data: Record<string, unknown> = {},
    reasonCode: string | null = null,
): WorkerCommandResult {
    return {
        protocol: RETAILER_PROTOCOL,
        status,
        reason_code: reasonCode,
        verification_checksum:
            status === 'succeeded' ? canonicalChecksum(data) : null,
        data,
    };
}

export function canonicalChecksum(value: unknown): string {
    return createHash('sha256')
        .update(JSON.stringify(canonicalize(value)))
        .digest('hex');
}

function canonicalize(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(canonicalize);
    }

    if (isRecord(value)) {
        return Object.fromEntries(
            Object.entries(value)
                .sort(([left], [right]) => left.localeCompare(right))
                .map(([key, child]) => [key, canonicalize(child)]),
        );
    }

    return value;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function requiredIdentifier(value: unknown, field: string): string {
    if (
        typeof value !== 'string' ||
        value.trim() === '' ||
        value.length > 240
    ) {
        throw new WorkerProtocolError(`invalid_${field}`);
    }

    return value;
}
