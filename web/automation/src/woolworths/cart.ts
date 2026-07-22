import type { Page } from 'playwright-core';

import { WorkerFailure, isRecord } from '../protocol.js';
import {
    cartItemSelector,
    connectOverCdp,
    ensureCartSurface,
    modeDiagnostics,
    navigate,
    observeSafety,
    productCardSelector,
    stagehandRecoveryUnavailable,
    woolworthsUrl,
} from './browser.js';

export type CartLine = {
    external_id: string | null;
    external_product_id: string | null;
    product_name: string;
    quantity: number | null;
    unit: string | null;
    unit_price: number | null;
    total_price: number | null;
};

export type CartToolContext = {
    cdpUrl: string | null;
    fixtureMode: boolean;
};

function emptyCartPayload(
    context: CartToolContext,
    extras: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        lines: [],
        total: 0,
        currency: 'AUD',
        bot_detected: false,
        sensitive_screen: false,
        ...modeDiagnostics(context),
        ...extras,
    };
}

export function fixtureInspectCart(
    context: CartToolContext,
): Record<string, unknown> {
    return emptyCartPayload(context);
}

export function fixtureClearCart(
    context: CartToolContext,
): Record<string, unknown> {
    return emptyCartPayload(context, {
        cleared: true,
        reason: 'Fixture cart clear succeeded without CDP.',
    });
}

export function fixtureAddProduct(
    context: CartToolContext,
    payload: Record<string, unknown>,
): Record<string, unknown> {
    const product = isRecord(payload.product) ? payload.product : payload;
    const externalId =
        typeof product.external_id === 'string' ? product.external_id : 'fixture-product';
    const name =
        typeof product.name === 'string'
            ? product.name
            : typeof product.product_name === 'string'
              ? product.product_name
              : 'Fixture product';
    const quantity =
        typeof product.quantity === 'number' && Number.isFinite(product.quantity)
            ? product.quantity
            : 1;
    const line: CartLine = {
        external_id: externalId,
        external_product_id: externalId,
        product_name: name,
        quantity,
        unit: null,
        unit_price: null,
        total_price: null,
    };

    return {
        status: 'matched',
        verified: true,
        product: line,
        cart: {
            lines: [line],
            total: 0,
            currency: 'AUD',
            bot_detected: false,
            sensitive_screen: false,
        },
        reason: 'Fixture add_product succeeded without CDP.',
        ...modeDiagnostics(context),
    };
}

export async function liveInspectCart(
    context: CartToolContext,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        return emptyCartPayload(context, {
            reason: 'Stagehand cart inspection stub; CDP was not available.',
        });
    }

    const { browser, page } = await connectOverCdp(context.cdpUrl);

    try {
        await ensureCartSurface(page);

        return await inspectCurrentCart(page);
    } finally {
        await browser.close().catch(() => undefined);
    }
}

export async function liveClearCart(
    context: CartToolContext,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const { browser, page } = await connectOverCdp(context.cdpUrl);

    try {
        await ensureCartSurface(page);

        for (let attempt = 0; attempt < 50; attempt++) {
            const line = page.locator(cartItemSelector).first();

            if ((await line.count()) === 0) {
                break;
            }

            const remove = line
                .locator(
                    'button[aria-label*="remove" i], button[data-testid*="remove" i], button:has-text("Remove")',
                )
                .first();

            if ((await remove.count()) === 0) {
                stagehandRecoveryUnavailable('clear_cart');
            }

            await remove.click({ timeout: 5_000 });
            await page.waitForTimeout(350);
        }

        const inspection = await inspectCurrentCart(page);

        if ((inspection.lines as CartLine[]).length > 0) {
            throw new WorkerFailure(
                'cart_clear_unverified',
                'The existing cart was not empty after the approved replace action.',
            );
        }

        return {
            ...inspection,
            cleared: true,
        };
    } finally {
        await browser.close().catch(() => undefined);
    }
}

export async function liveAddProduct(
    context: CartToolContext,
    payload: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    if (context.cdpUrl === null) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const product = isRecord(payload.product) ? payload.product : payload;
    const desiredExternalId =
        typeof product.external_id === 'string' ? product.external_id : null;
    const desiredName =
        typeof product.name === 'string'
            ? product.name
            : typeof product.product_name === 'string'
              ? product.product_name
              : null;
    const packCount = positiveInteger(product.quantity, 1);

    if (desiredName === null || desiredName === '') {
        throw new WorkerFailure(
            'invalid_command',
            'add_product requires a product name.',
        );
    }

    const { browser, page } = await connectOverCdp(context.cdpUrl);

    try {
        await ensureCartSurface(page);
        const beforeLines = await extractCartLines(page);
        const existing = findMatchingLine(
            beforeLines,
            desiredExternalId ?? desiredName,
        );
        const preExistingQuantity = existing?.quantity ?? 0;
        const targetCartQuantity = preExistingQuantity + packCount;

        if (
            existing &&
            existing.quantity !== null &&
            existing.quantity >= targetCartQuantity
        ) {
            const cart = await inspectCurrentCart(page);

            return {
                status: 'matched',
                verified: true,
                product: existing,
                cart,
                reason: 'The requested item was already visible in the cart.',
            };
        }

        const searchUrl = woolworthsUrl('/shop/search/products');
        searchUrl.searchParams.set('searchTerm', desiredName);
        await navigate(page, searchUrl.toString());

        const candidates = await extractProductCandidates(page);
        const ranked = candidates
            .map((candidate) => ({
                ...candidate,
                score:
                    desiredExternalId !== null &&
                    candidate.external_id === desiredExternalId
                        ? 1
                        : productScore(candidate.product_name, desiredName),
            }))
            .sort((left, right) => right.score - left.score);
        const best = ranked[0];
        const runnerUp = ranked[1];

        if (
            !best ||
            best.score < 0.72 ||
            (runnerUp && best.score - runnerUp.score < 0.05)
        ) {
            stagehandRecoveryUnavailable('add_product search ranking');
        }

        const card = page.locator(productCardSelector).nth(best.index);
        const add = card
            .locator(
                'button[aria-label*="add" i], button[data-testid*="add" i], button:has-text("Add")',
            )
            .first();

        if ((await add.count()) === 0) {
            stagehandRecoveryUnavailable('add_product add control');
        }

        await add.click({ timeout: 8_000 });
        await page.waitForTimeout(750);
        await ensureCartSurface(page);

        let lines = await extractCartLines(page);
        let verified =
            findMatchingLine(lines, best.external_id ?? best.product_name) ??
            findMatchingLine(lines, best.product_name);

        if (!verified) {
            stagehandRecoveryUnavailable('add_product cart verification');
        }

        if (packCount > 1) {
            const cartLine = page
                .locator(cartItemSelector)
                .filter({ hasText: verified.product_name })
                .first();
            const increase = cartLine
                .locator(
                    'button[aria-label*="increase" i], button[data-testid*="increase" i], button:has-text("+")',
                )
                .first();

            if ((await increase.count()) === 0) {
                stagehandRecoveryUnavailable('add_product quantity increase');
            }

            for (let count = 1; count < packCount; count++) {
                await increase.click({ timeout: 5_000 });
                await page.waitForTimeout(300);
            }

            lines = await extractCartLines(page);
            verified =
                findMatchingLine(lines, best.external_id ?? best.product_name) ??
                findMatchingLine(lines, best.product_name);
        }

        if (!verified) {
            throw new WorkerFailure(
                'cart_line_unverified',
                'The cart line disappeared during quantity verification.',
            );
        }

        if (
            verified.quantity !== null &&
            verified.quantity < targetCartQuantity
        ) {
            throw new WorkerFailure(
                'cart_quantity_unverified',
                'The verified cart quantity did not reach the requested amount.',
            );
        }

        const cart = await inspectCurrentCart(page);

        return {
            status: sameProductName(verified.product_name, desiredName)
                ? 'matched'
                : 'substituted',
            verified: true,
            product: verified,
            cart,
            reason: 'Product identity and visible cart persistence were verified.',
        };
    } finally {
        await browser.close().catch(() => undefined);
    }
}

async function inspectCurrentCart(page: Page): Promise<Record<string, unknown>> {
    const [observation, lines, total] = await Promise.all([
        observeSafety(page),
        extractCartLines(page),
        extractCartTotal(page),
    ]);

    return {
        lines,
        total,
        currency: 'AUD',
        bot_detected: observation.bot_detected,
        sensitive_screen: observation.sensitive_screen,
    };
}

async function extractCartLines(page: Page): Promise<CartLine[]> {
    return page.locator(cartItemSelector).evaluateAll((elements) => {
        const money = (value: string): number | null => {
            const match = value
                .replace(/,/g, '')
                .match(/\$\s*(\d+(?:\.\d{1,2})?)/);

            return match ? Number(match[1]) : null;
        };
        const numeric = (value: string | null | undefined): number | null => {
            if (!value) {
                return null;
            }

            const match = value.match(/\d+(?:\.\d+)?/);

            return match ? Number(match[0]) : null;
        };

        return elements
            .map((element) => {
                const productLink = element.querySelector(
                    'a[href*="/shop/productdetails/"]',
                ) as HTMLAnchorElement | null;
                const titleElement = element.querySelector(
                    '[data-testid*="title" i], [class*="title" i], h2, h3, h4, a[href*="/shop/productdetails/"]',
                );
                const name = (titleElement?.textContent ?? '').trim();

                if (!name) {
                    return null;
                }

                const href = productLink?.href ?? '';
                const idFromHref =
                    href.match(/productdetails\/(\d+)/i)?.[1] ?? null;
                const idFromData =
                    element.getAttribute('data-product-id') ??
                    element.getAttribute('data-testid')?.match(/\d+/)?.[0] ??
                    null;
                const quantityInput = element.querySelector(
                    'input[aria-label*="quantity" i], input[name*="quantity" i], select[aria-label*="quantity" i]',
                ) as HTMLInputElement | HTMLSelectElement | null;
                const quantityText =
                    quantityInput?.value ??
                    element.querySelector(
                        '[data-testid*="quantity" i], [class*="quantity" i]',
                    )?.textContent;
                const allText = (element.textContent ?? '').replace(/\s+/g, ' ');
                const prices = [
                    ...allText.matchAll(/\$\s*\d+(?:\.\d{1,2})?/g),
                ].map((match) => money(match[0]));
                const validPrices = prices.filter(
                    (price): price is number => price !== null,
                );
                const externalId = idFromData ?? idFromHref;

                return {
                    external_id: externalId,
                    external_product_id: externalId,
                    product_name: name,
                    quantity: numeric(quantityText),
                    unit: null,
                    unit_price: validPrices[0] ?? null,
                    total_price:
                        validPrices[validPrices.length - 1] ??
                        validPrices[0] ??
                        null,
                };
            })
            .filter((line): line is NonNullable<typeof line> => line !== null);
    });
}

async function extractCartTotal(page: Page): Promise<number | null> {
    const text = await page
        .locator(
            '[data-testid*="cart-total" i], [data-testid*="trolley-total" i], [class*="cart-total" i], [class*="CartTotal" i]',
        )
        .last()
        .textContent({ timeout: 1_500 })
        .catch(() => null);

    if (!text) {
        return null;
    }

    const match = text.replace(/,/g, '').match(/\$\s*(\d+(?:\.\d{1,2})?)/);

    return match ? Number(match[1]) : null;
}

async function extractProductCandidates(page: Page): Promise<
    Array<{
        index: number;
        external_id: string | null;
        product_name: string;
        unit_price: number | null;
    }>
> {
    return page.locator(productCardSelector).evaluateAll((elements) =>
        elements
            .map((element, index) => {
                const link = element.querySelector(
                    'a[href*="/shop/productdetails/"]',
                ) as HTMLAnchorElement | null;
                const title = (
                    element.querySelector(
                        '[data-testid*="title" i], [class*="title" i], h2, h3, h4, a[href*="/shop/productdetails/"]',
                    )?.textContent ?? ''
                ).trim();

                if (!title) {
                    return null;
                }

                const priceText = (
                    element.querySelector(
                        '[data-testid*="price" i], [class*="price" i]',
                    )?.textContent ?? ''
                ).replace(/,/g, '');
                const price = priceText.match(/\$\s*(\d+(?:\.\d{1,2})?)/)?.[1];
                const externalId =
                    element.getAttribute('data-product-id') ??
                    link?.href.match(/productdetails\/(\d+)/i)?.[1] ??
                    null;

                return {
                    index,
                    external_id: externalId,
                    product_name: title,
                    unit_price: price ? Number(price) : null,
                };
            })
            .filter(
                (candidate): candidate is NonNullable<typeof candidate> =>
                    candidate !== null,
            ),
    );
}

function findMatchingLine(
    lines: CartLine[],
    identity: string,
): CartLine | null {
    return (
        lines.find(
            (line) =>
                line.external_id === identity ||
                line.external_product_id === identity ||
                sameProductName(line.product_name, identity),
        ) ?? null
    );
}

function productScore(candidate: string, requested: string): number {
    const left = normalise(candidate);
    const right = normalise(requested);

    if (left === right) {
        return 1;
    }

    if (left.includes(right) || right.includes(left)) {
        return 0.88;
    }

    const leftTokens = new Set(left.split(' ').filter(Boolean));
    const rightTokens = new Set(right.split(' ').filter(Boolean));
    const overlap = [...rightTokens].filter((token) =>
        leftTokens.has(token),
    ).length;

    return overlap / Math.max(leftTokens.size, rightTokens.size, 1);
}

function sameProductName(left: string, right: string): boolean {
    return productScore(left, right) >= 0.82;
}

function normalise(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');
}

function positiveInteger(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0
        ? value
        : fallback;
}
