import { createInterface } from 'node:readline';
import { pathToFileURL } from 'node:url';

import { chromium } from 'playwright-core';
import type { Browser, Locator, Page } from 'playwright-core';

export const protocolVersion = 'chef.browser.v1' as const;
const allowedHosts = new Set(['woolworths.com.au', 'www.woolworths.com.au']);
const cartPath = '/shop/checkout/cart';
const cartSurfacePaths = new Set([cartPath, '/checkout']);
const cartItemSelector = [
    '[data-testid*="cart-item"]',
    '[data-testid*="trolley-item"]',
    '[data-testid*="CartItem"]',
    '[class*="cart-item"]',
    '[class*="CartItem"]',
].join(',');
const productCardSelector = [
    '[data-testid*="product-card"]',
    '[data-testid*="ProductCard"]',
    'wow-product-card',
    '[class*="product-card"]',
    '[class*="ProductCard"]',
].join(',');

export type CommandEnvelope = {
    version: typeof protocolVersion;
    type: string;
    payload?: Record<string, unknown>;
};

export type WorkerResponse = {
    version: typeof protocolVersion;
    ok: boolean;
    payload?: Record<string, unknown>;
    error_code?: string;
    error_message?: string;
};

type CartLine = {
    external_product_id: string | null;
    product_name: string;
    quantity: number | null;
    unit: string | null;
    unit_price: number | null;
    total_price: number | null;
};

type Requirement = {
    name: string;
    quantity: number | null;
    unit: string | null;
    accept_substitutes: boolean;
    maximum_price: number | null;
    product_match: Record<string, unknown> | null;
    pre_existing_quantity: number;
};

export class WorkerFailure extends Error {
    constructor(
        public readonly code: string,
        public readonly safeMessage: string,
    ) {
        super(safeMessage);
    }
}

async function main(): Promise<void> {
    let exitCode = 0;

    try {
        const command = await readCommand();
        const cdpUrl = process.env.CHEF_BROWSER_CDP_URL;

        if (!cdpUrl || !/^(wss?|https):\/\//i.test(cdpUrl)) {
            throw new WorkerFailure(
                'missing_cdp_url',
                'The browser connection was not available.',
            );
        }

        const connection = await connectRemoteBrowser(cdpUrl);
        const page = connection.page;

        const payload = await executeCommand(page, command);
        await respond({ version: protocolVersion, ok: true, payload });
    } catch (error) {
        const failure =
            error instanceof WorkerFailure
                ? error
                : error instanceof Error && error.name === 'TimeoutError'
                  ? new WorkerFailure(
                        'worker_timeout',
                        'Woolworths did not expose a verifiable cart control in time.',
                    )
                  : new WorkerFailure(
                        'worker_failure',
                        'The browser worker stopped before it could verify the step.',
                    );
        await respond({
            version: protocolVersion,
            ok: false,
            error_code: failure.code,
            error_message: failure.safeMessage,
        });
        // A typed rejection is a valid protocol result. The Laravel policy
        // layer decides whether to pause, retry, or fail the run.
        exitCode = 0;
    }

    process.exit(exitCode);
}

export async function connectRemoteBrowser(
    cdpUrl: string,
): Promise<{ browser: Browser; page: Page; connect_ms: number }> {
    if (!/^(wss?|https):\/\//i.test(cdpUrl)) {
        throw new WorkerFailure(
            'missing_cdp_url',
            'The browser connection was not available.',
        );
    }

    const startedAt = performance.now();
    const browser = await chromium.connectOverCDP(cdpUrl);
    const context = browser.contexts()[0];

    if (!context) {
        throw new WorkerFailure(
            'missing_browser_context',
            'The remote browser did not expose a usable context.',
        );
    }

    const openPages = context.pages();
    const page =
        openPages.find((candidate) => isWoolworthsPage(candidate.url())) ??
        openPages[0] ??
        (await context.newPage());
    page.on('download', (download) => void download.cancel());
    page.on('filechooser', (chooser) => void chooser.setFiles([]));

    return {
        browser,
        page,
        connect_ms: Math.round((performance.now() - startedAt) * 100) / 100,
    };
}

async function readCommand(): Promise<CommandEnvelope> {
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
                'The browser worker command was not valid JSON.',
            );
        }

        if (!isRecord(value) || value.version !== protocolVersion) {
            throw new WorkerFailure(
                'protocol_mismatch',
                'The browser worker protocol version did not match.',
            );
        }

        if (typeof value.type !== 'string') {
            throw new WorkerFailure(
                'invalid_command',
                'The browser worker command did not have a valid type.',
            );
        }

        return value as CommandEnvelope;
    }

    throw new WorkerFailure(
        'missing_command',
        'The browser worker did not receive a command.',
    );
}

export async function executeCommand(
    page: Page,
    command: CommandEnvelope,
): Promise<Record<string, unknown>> {
    const payload = command.payload ?? {};

    switch (command.type) {
        case 'navigate':
            return navigate(page, requiredString(payload.url, 'url'));
        case 'probe_authentication':
            return probeAuthentication(
                page,
                requiredString(payload.url, 'url'),
            );
        case 'inspect_cart':
        case 'reconcile_cart':
            return inspectCart(page, requiredString(payload.url, 'url'));
        case 'clear_cart':
            return clearCart(page, requiredString(payload.url, 'url'));
        case 'prepare_and_verify_item':
            return prepareAndVerifyItem(
                page,
                requiredString(payload.url, 'url'),
                parseRequirement(payload.requirement),
            );
        case 'prepare_item':
            return prepareItem(page, parseRequirement(payload.requirement));
        case 'capture':
            return capture(page);
        case 'execute_action':
            return executeAction(
                page,
                requiredRecord(payload.action, 'action'),
            );
        default:
            throw new WorkerFailure(
                'unsupported_command',
                'The browser worker command is not allowlisted.',
            );
    }
}

async function prepareAndVerifyItem(
    page: Page,
    cartUrl: string,
    requirement: Requirement,
): Promise<Record<string, unknown>> {
    const beforeStartedAt = performance.now();
    const before = await inspectCart(page, cartUrl);
    const beforeMs = performance.now() - beforeStartedAt;
    const preparationStartedAt = performance.now();
    const preparation = await prepareItem(page, requirement);
    const preparationMs = performance.now() - preparationStartedAt;
    const afterStartedAt = performance.now();
    const after = await inspectCart(page, cartUrl);
    const afterMs = performance.now() - afterStartedAt;

    return {
        preparation,
        before,
        after,
        timings: {
            before_inspection_ms: Math.round(beforeMs * 100) / 100,
            deterministic_preparation_ms: Math.round(preparationMs * 100) / 100,
            after_inspection_ms: Math.round(afterMs * 100) / 100,
        },
    };
}

async function navigate(
    page: Page,
    rawUrl: string,
): Promise<Record<string, unknown>> {
    const url = assertAllowedUrl(rawUrl, true);
    await page.goto(url.toString(), {
        waitUntil: 'domcontentloaded',
        timeout: 30_000,
    });
    await page.waitForTimeout(500);

    return {
        url: page.url(),
        verified: allowedUrl(page.url(), true),
    };
}

async function probeAuthentication(
    page: Page,
    rawUrl: string,
): Promise<Record<string, unknown>> {
    const url = assertAllowedUrl(rawUrl, true);

    if (!isAllowedCartSurfaceUrl(page.url())) {
        try {
            await page.goto(url.toString(), {
                waitUntil: 'commit',
                timeout: 10_000,
            });
        } catch {
            throw new WorkerFailure(
                'authentication_navigation_failed',
                'Woolworths did not open the protected cart in time.',
            );
        }

        await page
            .waitForLoadState('domcontentloaded', { timeout: 1_500 })
            .catch(() => undefined);
        await page.waitForTimeout(250);
    }

    const state = await waitForAuthenticationState(page);
    const { loginRequested, cartMarker, observation } = state;
    const sensitiveScreen = observation.sensitive_screen && !cartMarker;
    const authenticated =
        !loginRequested &&
        cartMarker &&
        !observation.bot_detected &&
        !sensitiveScreen;

    return {
        authenticated,
        reason: authenticated
            ? 'Protected Woolworths cart probe passed.'
            : authenticationFailureReason(
                  loginRequested,
                  cartMarker,
                  observation.bot_detected,
                  sensitiveScreen,
              ),
        bot_detected: observation.bot_detected,
        sensitive_screen: sensitiveScreen && !loginRequested,
    };
}

async function waitForAuthenticationState(page: Page): Promise<{
    loginRequested: boolean;
    cartMarker: boolean;
    observation: Awaited<ReturnType<typeof observeSafety>>;
}> {
    const deadline = Date.now() + 6_000;
    let latest = await readAuthenticationState(page);

    while (
        !latest.loginRequested &&
        !latest.cartMarker &&
        !latest.observation.bot_detected &&
        !latest.observation.sensitive_screen &&
        Date.now() < deadline
    ) {
        await page.waitForTimeout(400);
        latest = await readAuthenticationState(page);
    }

    return latest;
}

async function readAuthenticationState(page: Page): Promise<{
    loginRequested: boolean;
    cartMarker: boolean;
    observation: Awaited<ReturnType<typeof observeSafety>>;
}> {
    const path = safePath(page.url());
    const body = await bodyText(page, 1_500);
    const [observation, passwordFields] = await Promise.all([
        observeSafety(page, body),
        page.locator('input[type="password"]').count(),
    ]);
    const bodyExcerpt = body.slice(0, 10_000);
    const loginRequested =
        path.includes('securelogin') ||
        path.includes('/auth/login') ||
        /\b(log in or sign up|welcome to woolworths online|email address)\b/i.test(
            bodyExcerpt,
        ) ||
        passwordFields > 0;
    const cartSignals = [
        /\byour (cart|trolley|order)\b/i,
        /\bshopping (cart|trolley)\b/i,
        /\breview your items\b/i,
        /\byour cart is empty\b/i,
        /\bdelivery location\b/i,
        /\bselect a date and time\b/i,
        /\btotal\s*\(incl\.? gst\)\b/i,
    ].filter((pattern) => pattern.test(bodyExcerpt)).length;
    const cartMarker = isCartSurfacePath(path) && cartSignals > 0;

    return { loginRequested, cartMarker, observation };
}

function authenticationFailureReason(
    loginRequested: boolean,
    cartMarker: boolean,
    botDetected: boolean,
    sensitiveScreen: boolean,
): string {
    if (loginRequested) {
        return 'Woolworths still shows a sign-in screen.';
    }

    if (botDetected) {
        return 'Woolworths presented bot detection during the protected-cart check.';
    }

    if (sensitiveScreen) {
        return 'Woolworths opened an unexpected sensitive screen instead of the cart.';
    }

    if (!cartMarker) {
        return 'Woolworths opened the protected checkout, but Chef could not identify its cart state.';
    }

    return 'The protected Woolworths cart could not be verified.';
}

async function inspectCart(
    page: Page,
    rawUrl: string,
): Promise<Record<string, unknown>> {
    if (!isAllowedCartSurfaceUrl(page.url())) {
        await navigate(page, rawUrl);
    }

    return inspectCurrentCart(page);
}

async function inspectCurrentCart(
    page: Page,
): Promise<Record<string, unknown>> {
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

async function clearCart(
    page: Page,
    rawUrl: string,
): Promise<Record<string, unknown>> {
    if (!isAllowedCartSurfaceUrl(page.url())) {
        await navigate(page, rawUrl);
    }

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
            throw new WorkerFailure(
                'cart_clear_unverified',
                'The existing cart could not be cleared with known controls.',
            );
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

    return inspection;
}

async function prepareItem(
    page: Page,
    requirement: Requirement,
): Promise<Record<string, unknown>> {
    if (!isAllowedCartSurfaceUrl(page.url())) {
        await navigate(page, woolworthsUrl(cartPath).toString());
    }

    const existingLines = await extractCartLines(page);
    const desiredProductName =
        typeof requirement.product_match?.product_name === 'string'
            ? requirement.product_match.product_name
            : requirement.name;
    const desiredExternalId =
        typeof requirement.product_match?.external_id === 'string'
            ? requirement.product_match.external_id
            : null;
    const existing = findMatchingLine(
        existingLines,
        desiredExternalId ?? desiredProductName,
    );
    const packCount = positiveInteger(requirement.product_match?.pack_count, 1);
    const targetCartQuantity = requirement.pre_existing_quantity + packCount;

    if (
        existing &&
        existing.quantity !== null &&
        existing.quantity >= targetCartQuantity
    ) {
        return {
            status: sameProductName(existing.product_name, requirement.name)
                ? 'matched'
                : 'substituted',
            product: existing,
            reason: 'The requested item was already visible in the cart.',
        };
    }

    const searchUrl = woolworthsUrl('/shop/search/products');
    searchUrl.searchParams.set('searchTerm', desiredProductName);
    await navigate(page, searchUrl.toString());
    const candidates = await extractProductCandidates(page);
    const ranked = candidates
        .map((candidate) => ({
            ...candidate,
            score:
                desiredExternalId !== null &&
                candidate.external_product_id === desiredExternalId
                    ? 1
                    : productScore(candidate.product_name, desiredProductName),
        }))
        .sort((left, right) => right.score - left.score);
    const best = ranked[0];
    const runnerUp = ranked[1];

    if (
        !best ||
        best.score < 0.72 ||
        (runnerUp && best.score - runnerUp.score < 0.05)
    ) {
        return {
            status: 'searching',
            reason: 'Known Woolworths controls did not produce one confident candidate.',
            requires_computer_use: true,
        };
    }

    if (
        requirement.maximum_price !== null &&
        best.unit_price !== null &&
        best.unit_price > requirement.maximum_price
    ) {
        return {
            status: 'awaiting_decision',
            reason: 'The best candidate is above the saved maximum price.',
            product: best,
        };
    }

    const substituted = !sameProductName(best.product_name, requirement.name);

    if (substituted && !requirement.accept_substitutes) {
        return {
            status: 'awaiting_decision',
            reason: 'The best candidate is a substitution outside the saved policy.',
            product: best,
        };
    }

    const card = page.locator(productCardSelector).nth(best.index);
    const add = card
        .locator(
            'button[aria-label*="add" i], button[data-testid*="add" i], button:has-text("Add")',
        )
        .first();

    if ((await add.count()) === 0) {
        return {
            status: 'searching',
            reason: 'The selected product did not expose a known add control.',
            requires_computer_use: true,
        };
    }

    await assertSafePointerTarget(add);
    await add.click({ timeout: 8_000 });
    await page.waitForTimeout(750);
    await navigate(page, woolworthsUrl(cartPath).toString());
    let lines = await extractCartLines(page);
    let verified =
        findMatchingLine(
            lines,
            best.external_product_id ?? best.product_name,
        ) ?? findMatchingLine(lines, best.product_name);

    if (!verified) {
        return {
            status: 'searching',
            reason: 'The add click did not produce a verified cart line.',
            requires_computer_use: true,
        };
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
            return {
                status: 'searching',
                reason: 'Chef could not verify the requested pack count with known controls.',
                requires_computer_use: true,
            };
        }

        for (let count = 1; count < packCount; count++) {
            await assertSafePointerTarget(increase);
            await increase.click({ timeout: 5_000 });
            await page.waitForTimeout(300);
        }

        lines = await extractCartLines(page);
        verified =
            findMatchingLine(
                lines,
                best.external_product_id ?? best.product_name,
            ) ?? findMatchingLine(lines, best.product_name);
    }

    if (!verified) {
        return {
            status: 'searching',
            reason: 'The cart line disappeared during quantity verification.',
            requires_computer_use: true,
        };
    }

    if (verified.quantity !== null && verified.quantity < targetCartQuantity) {
        return {
            status: 'searching',
            reason: 'The verified cart quantity did not increase beyond the pre-existing cart line.',
            requires_computer_use: true,
        };
    }

    return {
        status: substituted ? 'substituted' : 'matched',
        product: verified,
        reason: 'Product identity and visible cart persistence were verified.',
    };
}

async function capture(page: Page): Promise<Record<string, unknown>> {
    assertAllowedUrl(page.url(), false);
    const [safety, screenshot] = await Promise.all([
        observeSafety(page),
        page.screenshot({
            type: 'png',
            fullPage: false,
            animations: 'disabled',
        }),
    ]);

    return {
        screenshot: `data:image/png;base64,${screenshot.toString('base64')}`,
        url: page.url(),
        ...safety,
    };
}

async function executeAction(
    page: Page,
    action: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    assertAllowedUrl(page.url(), false);
    const safety = await observeSafety(page);

    if (safety.bot_detected || safety.sensitive_screen) {
        throw new WorkerFailure(
            'unsafe_page',
            'The browser action was blocked on a sensitive or bot-detection page.',
        );
    }

    const type = requiredString(action.type, 'action.type');

    switch (type) {
        case 'screenshot':
            break;
        case 'wait':
            await page.waitForTimeout(1_000);
            break;
        case 'click':
        case 'double_click': {
            const x = boundedNumber(action.x, 'action.x', 0, 4_000);
            const y = boundedNumber(action.y, 'action.y', 0, 4_000);
            await assertSafeCoordinates(page, x, y);

            if (type === 'click') {
                await page.mouse.click(x, y);
            } else {
                await page.mouse.dblclick(x, y);
            }

            break;
        }
        case 'move': {
            const x = boundedNumber(action.x, 'action.x', 0, 4_000);
            const y = boundedNumber(action.y, 'action.y', 0, 4_000);
            await page.mouse.move(x, y);
            break;
        }
        case 'scroll': {
            const x = optionalNumber(action.x, 0);
            const y = optionalNumber(action.y, 0);
            const scrollX = boundedNumber(
                action.scroll_x ?? 0,
                'action.scroll_x',
                -2_000,
                2_000,
            );
            const scrollY = boundedNumber(
                action.scroll_y ?? 0,
                'action.scroll_y',
                -2_000,
                2_000,
            );
            await page.mouse.move(x, y);
            await page.mouse.wheel(scrollX, scrollY);
            break;
        }
        case 'type': {
            const text = requiredString(action.text, 'action.text');

            if (text.length > 250 || /[\r\n]/.test(text)) {
                throw new WorkerFailure(
                    'unsafe_typing',
                    'The browser worker rejected an unsafe typing action.',
                );
            }

            const active = await activeElementSafety(page);

            if (active.sensitive_field || !active.editable) {
                throw new WorkerFailure(
                    'sensitive_field',
                    'Typing into authentication or sensitive fields is blocked.',
                );
            }

            await page.keyboard.type(text, { delay: 15 });
            break;
        }
        case 'keypress': {
            const rawKeys = Array.isArray(action.keys)
                ? action.keys
                : [action.key];
            const keys = rawKeys.filter(
                (key): key is string => typeof key === 'string',
            );
            const safeKeys = new Set([
                'ENTER',
                'ESCAPE',
                'TAB',
                'ARROWUP',
                'ARROWDOWN',
                'ARROWLEFT',
                'ARROWRIGHT',
                'BACKSPACE',
            ]);

            if (
                keys.length === 0 ||
                keys.some((key) => !safeKeys.has(key.toUpperCase()))
            ) {
                throw new WorkerFailure(
                    'unsafe_keypress',
                    'The browser worker rejected a keyboard shortcut outside the allowlist.',
                );
            }

            for (const key of keys) {
                await page.keyboard.press(key);
            }

            break;
        }
        default:
            throw new WorkerFailure(
                'unsafe_action',
                'The browser action is not allowlisted.',
            );
    }

    await page.waitForTimeout(400);
    assertAllowedUrl(page.url(), false);
    const after = await observeSafety(page);

    return {
        url: page.url(),
        verified: !after.bot_detected && !after.sensitive_screen,
        ...after,
    };
}

async function observeSafety(
    page: Page,
    knownBody?: string,
): Promise<{
    bot_detected: boolean;
    sensitive_screen: boolean;
    sensitive_field: boolean;
}> {
    const body = (knownBody ?? (await bodyText(page))).slice(0, 12_000);
    const path = safePath(page.url());
    const [active, captchaFrames, sensitiveFields] = await Promise.all([
        activeElementSafety(page),
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
    const sensitiveForm = sensitiveFields > 0;

    return {
        bot_detected: botDetected,
        sensitive_screen: sensitivePath || sensitiveForm,
        sensitive_field: active.sensitive_field,
    };
}

async function activeElementSafety(page: Page): Promise<{
    editable: boolean;
    sensitive_field: boolean;
}> {
    return page.evaluate(() => {
        const element = document.activeElement;

        if (!(element instanceof HTMLElement)) {
            return { editable: false, sensitive_field: false };
        }

        const input = element instanceof HTMLInputElement ? element : null;
        const textArea = element instanceof HTMLTextAreaElement;
        const editable = Boolean(
            input || textArea || element.isContentEditable,
        );
        const attributes = [
            input?.type,
            input?.autocomplete,
            input?.name,
            input?.id,
            input?.getAttribute('aria-label'),
        ]
            .filter(Boolean)
            .join(' ');
        const sensitive =
            input?.type === 'password' ||
            /password|username|email|login|sign.?in|otp|mfa|one.?time|address|payment|card|cc-|phone|tel/i.test(
                attributes,
            );

        return { editable, sensitive_field: sensitive };
    });
}

async function assertSafeCoordinates(
    page: Page,
    x: number,
    y: number,
): Promise<void> {
    const target = await page.evaluate(
        ({ pointX, pointY }) => {
            const element = document.elementFromPoint(pointX, pointY);

            if (!(element instanceof HTMLElement)) {
                return null;
            }

            const interactive = element.closest(
                'a,button,input,textarea,select,[role="button"],[contenteditable="true"]',
            ) as HTMLElement | null;
            const candidate = interactive ?? element;

            return {
                text: (candidate.innerText || candidate.textContent || '')
                    .trim()
                    .slice(0, 160),
                href:
                    candidate instanceof HTMLAnchorElement
                        ? candidate.href
                        : (candidate.closest('a')?.href ?? null),
                type:
                    candidate instanceof HTMLInputElement
                        ? candidate.type
                        : null,
                autocomplete:
                    candidate instanceof HTMLInputElement
                        ? candidate.autocomplete
                        : null,
                name:
                    candidate instanceof HTMLInputElement
                        ? candidate.name
                        : null,
            };
        },
        { pointX: x, pointY: y },
    );

    if (!target) {
        throw new WorkerFailure(
            'unverified_pointer_target',
            'The browser worker could not verify the pointer target.',
        );
    }

    const descriptor = [
        target.text,
        target.type,
        target.autocomplete,
        target.name,
    ].join(' ');

    if (
        /password|login|sign.?in|captcha|checkout|pay|address|delivery|pickup|order|upload|download|terms/i.test(
            descriptor,
        )
    ) {
        throw new WorkerFailure(
            'sensitive_pointer_target',
            'The browser worker blocked a sensitive pointer target.',
        );
    }

    if (target.href) {
        assertAllowedUrl(target.href, false);
    }
}

async function assertSafePointerTarget(locator: Locator): Promise<void> {
    const details = await locator.evaluate((element) => ({
        text: (element.textContent ?? '').trim().slice(0, 160),
        aria: element.getAttribute('aria-label') ?? '',
    }));

    if (
        /login|sign.?in|captcha|checkout|pay|address|delivery|pickup|order|upload|download|terms/i.test(
            `${details.text} ${details.aria}`,
        )
    ) {
        throw new WorkerFailure(
            'sensitive_pointer_target',
            'The browser worker blocked a sensitive pointer target.',
        );
    }
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
                const allText = (element.textContent ?? '').replace(
                    /\s+/g,
                    ' ',
                );
                const prices = [
                    ...allText.matchAll(/\$\s*\d+(?:\.\d{1,2})?/g),
                ].map((match) => money(match[0]));
                const validPrices = prices.filter(
                    (price): price is number => price !== null,
                );

                return {
                    external_product_id: idFromData ?? idFromHref,
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

    return parseMoney(text);
}

async function extractProductCandidates(page: Page): Promise<
    Array<{
        index: number;
        external_product_id: string | null;
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

                return {
                    index,
                    external_product_id:
                        element.getAttribute('data-product-id') ??
                        link?.href.match(/productdetails\/(\d+)/i)?.[1] ??
                        null,
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
    const score = productScore(left, right);

    return score >= 0.82;
}

function normalise(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');
}

function parseRequirement(value: unknown): Requirement {
    const record = requiredRecord(value, 'requirement');

    return {
        name: requiredString(record.name, 'requirement.name').slice(0, 200),
        quantity: nullableNumber(record.quantity),
        unit: typeof record.unit === 'string' ? record.unit.slice(0, 40) : null,
        accept_substitutes: record.accept_substitutes !== false,
        maximum_price: nullableNumber(record.maximum_price),
        product_match: isRecord(record.product_match)
            ? record.product_match
            : null,
        pre_existing_quantity:
            nullableNumber(record.pre_existing_quantity) ?? 0,
    };
}

function assertAllowedUrl(rawUrl: string, allowHumanLogin: boolean): URL {
    let url: URL;

    try {
        url = new URL(rawUrl);
    } catch {
        throw new WorkerFailure(
            'invalid_url',
            'The browser worker received an invalid URL.',
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

function allowedUrl(rawUrl: string, allowHumanLogin: boolean): boolean {
    try {
        assertAllowedUrl(rawUrl, allowHumanLogin);

        return true;
    } catch {
        return false;
    }
}

function woolworthsUrl(path: string): URL {
    return new URL(path, 'https://www.woolworths.com.au');
}

function safePath(rawUrl: string): string {
    try {
        return new URL(rawUrl).pathname.replace(/\/$/, '') || '/';
    } catch {
        return '';
    }
}

function isCartSurfacePath(path: string): boolean {
    return cartSurfacePaths.has(path.toLowerCase());
}

function isWoolworthsPage(rawUrl: string): boolean {
    try {
        const url = new URL(rawUrl);

        return (
            url.protocol === 'https:' &&
            allowedHosts.has(url.hostname.toLowerCase())
        );
    } catch {
        return false;
    }
}

function isAllowedCartSurfaceUrl(rawUrl: string): boolean {
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

async function bodyText(page: Page, timeout = 5_000): Promise<string> {
    return page
        .locator('body')
        .innerText({ timeout })
        .catch(() => '');
}

function requiredString(value: unknown, field: string): string {
    if (typeof value !== 'string' || value.length === 0) {
        throw new WorkerFailure(
            'invalid_command',
            `The browser worker command was missing ${field}.`,
        );
    }

    return value;
}

function requiredRecord(
    value: unknown,
    field: string,
): Record<string, unknown> {
    if (!isRecord(value)) {
        throw new WorkerFailure(
            'invalid_command',
            `The browser worker command was missing ${field}.`,
        );
    }

    return value;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function nullableNumber(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function optionalNumber(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : fallback;
}

function boundedNumber(
    value: unknown,
    field: string,
    minimum: number,
    maximum: number,
): number {
    if (
        typeof value !== 'number' ||
        !Number.isFinite(value) ||
        value < minimum ||
        value > maximum
    ) {
        throw new WorkerFailure(
            'invalid_action',
            `The browser action had an invalid ${field}.`,
        );
    }

    return value;
}

function positiveInteger(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0
        ? value
        : fallback;
}

function parseMoney(value: string | null): number | null {
    if (!value) {
        return null;
    }

    const match = value.replace(/,/g, '').match(/\$\s*(\d+(?:\.\d{1,2})?)/);

    return match ? Number(match[1]) : null;
}

function respond(response: WorkerResponse): Promise<void> {
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

const invokedPath = process.argv[1];

if (invokedPath && import.meta.url === pathToFileURL(invokedPath).href) {
    void main();
}
