import { WorkerFailure, isRecord } from '../protocol.js';
import {
    connectOverCdp,
    modeDiagnostics,
    stagehandRecoveryUnavailable,
} from './browser.js';

export type FulfilmentToolContext = {
    cdpUrl: string | null;
    fixtureMode: boolean;
};

function fixtureExpiresAt(): string {
    return new Date(Date.now() + 30 * 60_000).toISOString();
}

export function fixtureExtractFulfilmentOptions(
    context: FulfilmentToolContext,
    payload: Record<string, unknown>,
): Record<string, unknown> {
    const fulfilmentType =
        typeof payload.fulfilment_type === 'string' &&
        ['delivery', 'pickup'].includes(payload.fulfilment_type)
            ? payload.fulfilment_type
            : 'delivery';

    return {
        type: fulfilmentType,
        slots: [
            {
                id: 'fixture-slot-1',
                label: fulfilmentType === 'pickup'
                    ? 'Tomorrow 9am–10am (pickup)'
                    : 'Tomorrow 8am–10am (delivery)',
                starts_at: null,
                ends_at: null,
                fee: 0,
            },
        ],
        expires_at: fixtureExpiresAt(),
        reason: 'Fixture fulfilment options returned without CDP.',
        ...modeDiagnostics(context),
    };
}

export function fixtureApplyFulfilmentSlot(
    context: FulfilmentToolContext,
    payload: Record<string, unknown>,
): Record<string, unknown> {
    const slot = isRecord(payload.slot) ? payload.slot : {};
    const slotId = typeof slot.id === 'string' ? slot.id : 'fixture-slot-1';

    return {
        applied: true,
        slot_id: slotId,
        fulfilment_type:
            typeof slot.fulfilment_type === 'string'
                ? slot.fulfilment_type
                : 'delivery',
        reason: 'Fixture fulfilment slot applied without CDP.',
        ...modeDiagnostics(context),
    };
}

export async function liveExtractFulfilmentOptions(
    context: FulfilmentToolContext,
    payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const fulfilmentType =
        typeof payload.fulfilment_type === 'string' ? payload.fulfilment_type : '';

    if (!['delivery', 'pickup'].includes(fulfilmentType)) {
        throw new WorkerFailure(
            'invalid_command',
            'extract_fulfilment_options requires delivery or pickup.',
        );
    }

    const { browser } = await connectOverCdp(context.cdpUrl);

    try {
        // Deterministic Woolworths slot scraping lands with live selectors later.
        // Until then, miss → Stagehand recovery stub (clear error, no CUA).
        stagehandRecoveryUnavailable('extract_fulfilment_options');
    } finally {
        await browser.close().catch(() => undefined);
    }
}

export async function liveApplyFulfilmentSlot(
    context: FulfilmentToolContext,
    payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const slot = isRecord(payload.slot) ? payload.slot : {};

    if (typeof slot.id !== 'string' || slot.id === '') {
        throw new WorkerFailure(
            'invalid_command',
            'apply_fulfilment_slot requires a slot id.',
        );
    }

    const { browser } = await connectOverCdp(context.cdpUrl);

    try {
        stagehandRecoveryUnavailable('apply_fulfilment_slot');
    } finally {
        await browser.close().catch(() => undefined);
    }
}
