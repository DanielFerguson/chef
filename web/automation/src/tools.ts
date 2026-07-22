import { Stagehand } from '@browserbasehq/stagehand';

import type { StagehandCommand } from './protocol.js';
import {
    fixtureAddProduct,
    fixtureClearCart,
    fixtureInspectCart,
    liveAddProduct,
    liveClearCart,
    liveInspectCart,
} from './woolworths/cart.js';
import {
    fixtureApplyFulfilmentSlot,
    fixtureExtractFulfilmentOptions,
    liveApplyFulfilmentSlot,
    liveExtractFulfilmentOptions,
} from './woolworths/fulfilment.js';
import {
    fixtureExtractOrderConfirmation,
    fixtureSubmitOrder,
    liveExtractOrderConfirmation,
    liveSubmitOrder,
} from './woolworths/submit.js';

/**
 * Build a Stagehand instance attached to a transient Browserbase CDP URL.
 * Live cart tools prefer Playwright locators; Stagehand recovery remains stubbed.
 */
export function createStagehandForCdp(cdpUrl: string): Stagehand {
    return new Stagehand({
        env: 'LOCAL',
        verbose: 0,
        disablePino: true,
        disableAPI: true,
        localBrowserLaunchOptions: {
            cdpUrl,
        },
    });
}

export function stagehandRuntimeAvailable(): boolean {
    return typeof Stagehand === 'function';
}

export function isFixtureMode(): boolean {
    const value = process.env.CHEF_AUTOMATION_FIXTURE_MODE;

    return value === '1' || value === 'true';
}

export function resolveCdpUrl(): string | null {
    const cdpUrl = process.env.CHEF_BROWSER_CDP_URL;

    if (!cdpUrl || !/^(wss?|https):\/\//i.test(cdpUrl)) {
        return null;
    }

    return cdpUrl;
}

export type ToolContext = {
    command: StagehandCommand;
    cdpUrl: string | null;
    fixtureMode: boolean;
};

function modeDiagnostics(context: ToolContext): Record<string, unknown> {
    return {
        fixture_mode: context.fixtureMode,
        cdp_present: context.cdpUrl !== null,
        stagehand_available: stagehandRuntimeAvailable(),
    };
}

/**
 * Skeleton probe: structured placeholders when CDP is missing or fixture mode is on.
 * Live CDP paths also return a stub until Woolworths auth selectors land.
 */
export function probeAuth(context: ToolContext): Record<string, unknown> {
    if (context.fixtureMode || context.cdpUrl === null) {
        return {
            authenticated: false,
            reason: 'Stagehand auth probe placeholder; CDP was not available.',
            bot_detected: false,
            sensitive_screen: false,
            ...modeDiagnostics(context),
        };
    }

    return {
        authenticated: false,
        reason: 'Stagehand auth probe stub; live CDP supplied but probe not implemented.',
        bot_detected: false,
        sensitive_screen: false,
        ...modeDiagnostics(context),
    };
}

export async function executeTool(
    command: StagehandCommand,
    context: Omit<ToolContext, 'command'>,
    payload: Record<string, unknown> = {},
): Promise<Record<string, unknown>> {
    const fullContext: ToolContext = { command, ...context };
    const toolContext = {
        cdpUrl: fullContext.cdpUrl,
        fixtureMode: fullContext.fixtureMode,
    };

    switch (command) {
        case 'probe_auth':
            return probeAuth(fullContext);
        case 'inspect_cart':
            if (fullContext.fixtureMode || fullContext.cdpUrl === null) {
                return {
                    ...fixtureInspectCart(toolContext),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveInspectCart(toolContext)),
                ...modeDiagnostics(fullContext),
            };
        case 'clear_cart':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureClearCart(toolContext),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveClearCart(toolContext)),
                ...modeDiagnostics(fullContext),
            };
        case 'add_product':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureAddProduct(toolContext, payload),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveAddProduct(toolContext, payload)),
                ...modeDiagnostics(fullContext),
            };
        case 'extract_fulfilment_options':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureExtractFulfilmentOptions(toolContext, payload),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveExtractFulfilmentOptions(toolContext, payload)),
                ...modeDiagnostics(fullContext),
            };
        case 'apply_fulfilment_slot':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureApplyFulfilmentSlot(toolContext, payload),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveApplyFulfilmentSlot(toolContext, payload)),
                ...modeDiagnostics(fullContext),
            };
        case 'submit_order_with_default_payment':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureSubmitOrder(toolContext),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveSubmitOrder(toolContext)),
                ...modeDiagnostics(fullContext),
            };
        case 'extract_order_confirmation':
            if (fullContext.fixtureMode) {
                return {
                    ...fixtureExtractOrderConfirmation(toolContext),
                    stagehand_available: stagehandRuntimeAvailable(),
                };
            }

            return {
                ...(await liveExtractOrderConfirmation(toolContext)),
                ...modeDiagnostics(fullContext),
            };
    }
}
