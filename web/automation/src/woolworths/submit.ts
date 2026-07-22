import { WorkerFailure } from '../protocol.js';
import {
    connectOverCdp,
    modeDiagnostics,
    stagehandRecoveryUnavailable,
} from './browser.js';

export type SubmitToolContext = {
    cdpUrl: string | null;
    fixtureMode: boolean;
};

export function fixtureSubmitOrder(
    context: SubmitToolContext,
): Record<string, unknown> {
    return {
        ok: true,
        retailer_order_reference: null,
        confirmation_text: 'Fixture submit accepted with the default card on file.',
        used_default_payment: true,
        reason: 'Fixture submit_order_with_default_payment succeeded without CDP.',
        ...modeDiagnostics(context),
    };
}

export function fixtureExtractOrderConfirmation(
    context: SubmitToolContext,
): Record<string, unknown> {
    return {
        ok: true,
        retailer_order_reference: 'FIXTURE-ORDER-1001',
        confirmation_text: 'Order FIXTURE-ORDER-1001 confirmed (fixture).',
        reason: 'Fixture extract_order_confirmation succeeded without CDP.',
        ...modeDiagnostics(context),
    };
}

export async function liveSubmitOrder(
    context: SubmitToolContext,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const { browser } = await connectOverCdp(context.cdpUrl);

    try {
        // Address edits remain blocked; only default-card submit is allowlisted.
        // Live submit control discovery lands with deterministic selectors later.
        stagehandRecoveryUnavailable('submit_order_with_default_payment');
    } finally {
        await browser.close().catch(() => undefined);
    }
}

export async function liveExtractOrderConfirmation(
    context: SubmitToolContext,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const { browser } = await connectOverCdp(context.cdpUrl);

    try {
        stagehandRecoveryUnavailable('extract_order_confirmation');
    } finally {
        await browser.close().catch(() => undefined);
    }
}
