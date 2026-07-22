import { chromium } from 'playwright-core';
import type { Browser, Page } from 'playwright-core';

import { WorkerFailure } from '../protocol.js';

export const allowedHosts = new Set(['woolworths.com.au', 'www.woolworths.com.au']);
export const cartPath = '/shop/checkout/cart';
export const cartSurfacePaths = new Set([cartPath, '/checkout']);

export const cartItemSelector = [
    '[data-testid*="cart-item"]',
    '[data-testid*="trolley-item"]',
    '[data-testid*="CartItem"]',
    '[class*="cart-item"]',
    '[class*="CartItem"]',
].join(',');

export const productCardSelector = [
    '[data-testid*="product-card"]',
    '[data-testid*="ProductCard"]',
    'wow-product-card',
    '[class*="product-card"]',
    '[class*="ProductCard"]',
].join(',');

export type ConnectedBrowser = {
    browser: Browser;
    page: Page;
};

export async function connectOverCdp(cdpUrl: string): Promise<ConnectedBrowser> {
    const browser = await chromium.connectOverCDP(cdpUrl);
    const context = browser.contexts()[0] ?? (await browser.newContext());
    const page = context.pages()[0] ?? (await context.newPage());

    return { browser, page };
}

export function woolworthsUrl(path: string): URL {
    return new URL(path, 'https://www.woolworths.com.au');
}

export function safePath(rawUrl: string): string {
    try {
        return new URL(rawUrl).pathname.replace(/\/$/, '') || '/';
    } catch {
        return '';
    }
}

export function isCartSurfacePath(path: string): boolean {
    return cartSurfacePaths.has(path.toLowerCase());
}

export function isAllowedCartSurfaceUrl(rawUrl: string): boolean {
    try {
        const url = new URL(rawUrl);

        return (
            url.protocol === 'https:' &&
            allowedHosts.has(url.hostname.toLowerCase()) &&
            isCartSurfacePath(url.pathname.replace(/\/$/, '') || '/')
        );
    } catch {
        return false;
    }
}

export function assertAllowedUrl(rawUrl: string, allowHumanLogin = false): URL {
    let url: URL;

    try {
        url = new URL(rawUrl);
    } catch {
        throw new WorkerFailure(
            'invalid_url',
            'The retailer worker received an invalid URL.',
        );
    }

    if (
        url.protocol !== 'https:' ||
        !allowedHosts.has(url.hostname.toLowerCase())
    ) {
        throw new WorkerFailure(
            'origin_blocked',
            'Navigation outside Woolworths is blocked.',
        );
    }

    const path = url.pathname.replace(/\/$/, '') || '/';
    const humanLogin = allowHumanLogin && path.includes('securelogin');
    const sensitive =
        path !== cartPath &&
        /\b(checkout|securelogin|account|payment|address|delivery|pickup|orders?)\b/i.test(
            path,
        );

    if (sensitive && !humanLogin) {
        throw new WorkerFailure(
            'sensitive_navigation',
            'Navigation to a human-only Woolworths page is blocked.',
        );
    }

    return url;
}

export async function navigate(page: Page, rawUrl: string): Promise<void> {
    const url = assertAllowedUrl(rawUrl, true);
    await page.goto(url.toString(), {
        waitUntil: 'domcontentloaded',
        timeout: 30_000,
    });
    await page.waitForTimeout(500);
}

export async function ensureCartSurface(page: Page): Promise<void> {
    if (!isAllowedCartSurfaceUrl(page.url())) {
        await navigate(page, woolworthsUrl(cartPath).toString());
    }
}

export async function bodyText(page: Page, timeout = 5_000): Promise<string> {
    return page
        .locator('body')
        .innerText({ timeout })
        .catch(() => '');
}

export async function observeSafety(
    page: Page,
    knownBody?: string,
): Promise<{
    bot_detected: boolean;
    sensitive_screen: boolean;
}> {
    const body = (knownBody ?? (await bodyText(page))).slice(0, 12_000);
    const path = safePath(page.url());
    const [captchaFrames, sensitiveFields] = await Promise.all([
        page.locator('iframe[src*="captcha" i]').count(),
        page
            .locator(
                'input[type="password"], input[autocomplete*="cc-" i], input[autocomplete*="address" i], input[name*="payment" i]',
            )
            .count(),
    ]);
    const botDetected =
        /\b(captcha|verify you are human|unusual traffic|access denied|are you a robot)\b/i.test(
            body,
        ) || captchaFrames > 0;
    const sensitivePath =
        !isCartSurfacePath(path) &&
        /\b(checkout|securelogin|account|payment|address|delivery|pickup|orders?)\b/i.test(
            path,
        );

    return {
        bot_detected: botDetected,
        sensitive_screen: sensitivePath || sensitiveFields > 0,
    };
}

/**
 * Stagehand act/extract recovery is intentionally stubbed until wiring is lighter.
 * Deterministic Playwright locators must succeed without this path.
 */
export function stagehandRecoveryUnavailable(action: string): never {
    throw new WorkerFailure(
        'locator_miss_recovery_unavailable',
        `Playwright could not find Woolworths controls for ${action}; Stagehand recovery is not wired yet.`,
    );
}

export function modeDiagnostics(context: {
    fixtureMode: boolean;
    cdpUrl: string | null;
}): Record<string, unknown> {
    return {
        fixture_mode: context.fixtureMode,
        cdp_present: context.cdpUrl !== null,
    };
}
