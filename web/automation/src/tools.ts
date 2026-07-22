import { Stagehand } from '@browserbasehq/stagehand';

import type { StagehandCommand } from './protocol.js';

/**
 * Build a Stagehand instance attached to a transient Browserbase CDP URL.
 * Live tool bodies are implemented in later tasks; this keeps the dependency wired.
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

/**
 * Skeleton cart inspection: structured placeholders when CDP is missing or fixture mode is on.
 */
export function inspectCart(context: ToolContext): Record<string, unknown> {
    if (context.fixtureMode || context.cdpUrl === null) {
        return {
            lines: [],
            total: 0,
            currency: 'AUD',
            bot_detected: false,
            sensitive_screen: false,
            ...modeDiagnostics(context),
        };
    }

    return {
        lines: [],
        total: 0,
        currency: 'AUD',
        bot_detected: false,
        sensitive_screen: false,
        reason: 'Stagehand cart inspection stub; live CDP supplied but inspect not implemented.',
        ...modeDiagnostics(context),
    };
}

export function executeTool(
    command: StagehandCommand,
    context: Omit<ToolContext, 'command'>,
): Record<string, unknown> {
    const fullContext: ToolContext = { command, ...context };

    switch (command) {
        case 'probe_auth':
            return probeAuth(fullContext);
        case 'inspect_cart':
            return inspectCart(fullContext);
    }
}
