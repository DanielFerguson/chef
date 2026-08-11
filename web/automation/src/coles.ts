import type { Stagehand } from '@browserbasehq/stagehand';

import { canonicalChecksum, commandResult, isRecord } from './protocol.js';
import type { WorkerCommandResult } from './protocol.js';

type StagehandPage = ReturnType<Stagehand['context']['pages']>[number];

type Constraint = {
    constraint_id: number;
    kind: string;
    subject: string;
};

type SearchRequirement = {
    requirement_id: number;
    queries: string[];
    constraints: Constraint[];
    exact_sku: string | null;
};

type ProductCandidate = {
    requirement_id: number;
    sku: string;
    title: string;
    brand: string | null;
    is_home_brand: boolean;
    is_organic: boolean;
    attribute_evidence: {
        home_brand: { source: 'coles_search_card' };
        organic: { source: 'coles_search_card' };
    };
    semantic_key: string;
    origin_host: string;
    product_path: string;
    pack_quantity: number | null;
    pack_unit: 'g' | 'ml' | 'each' | null;
    price_cents: number | null;
    available: boolean;
    restricted_product: boolean;
    label_evidence: {
        constraints: Array<{
            constraint_id: number;
            status: 'compatible' | 'conflict' | 'unknown';
            source: string;
        }>;
    };
};

export type BasketLine = {
    sku: string;
    title: string;
    absolute_quantity: number;
    unit_price_cents: number | null;
    line_price_cents: number | null;
};

export type BasketInspection = {
    lines: BasketLine[];
    retailer_total_cents: number | null;
};

const COLES_BASE_URL = 'https://www.coles.com.au';
const ALLOWED_HOSTS = new Set(['coles.com.au', 'www.coles.com.au']);
const MAX_QUERIES = 3;
const MAX_CANDIDATES_PER_REQUIREMENT = 8;
const MAX_BASKET_MUTATIONS = 100;
let stagehandFallbackCount = 0;

export async function executeColesCommand(
    stagehand: Stagehand,
    command: string,
    payload: Record<string, unknown>,
): Promise<WorkerCommandResult> {
    stagehandFallbackCount = 0;
    const page = stagehand.context.pages()[0];

    if (page === undefined) {
        return withStagehandFallbackCount(
            commandResult('failed', {}, 'page_unavailable'),
            stagehandFallbackCount,
        );
    }

    let result: WorkerCommandResult;

    try {
        if (command === 'probe_auth') {
            result = await probeAuthentication(page);
        } else if (command === 'search_products') {
            result = await searchProducts(page, payload);
        } else if (command === 'inspect_basket') {
            result = commandResult(
                'succeeded',
                await inspectBasket(page, stagehand),
            );
        } else if (command === 'ensure_basket_empty') {
            result = await ensureBasketEmpty(page, stagehand, payload);
        } else if (command === 'ensure_basket_line') {
            result = await ensureBasketLine(page, stagehand, payload);
        } else {
            result = commandResult('failed', {}, 'unsupported_command');
        }
    } catch {
        result = commandResult(
            command.startsWith('ensure_') ? 'uncertain' : 'retryable',
            {},
            command.startsWith('ensure_')
                ? 'mutation_outcome_unknown'
                : 'retailer_temporarily_unavailable',
        );
    }

    return withStagehandFallbackCount(result, stagehandFallbackCount);
}

export function withStagehandFallbackCount(
    result: WorkerCommandResult,
    fallbackCount: number,
): WorkerCommandResult {
    const boundedCount = Math.max(0, Math.min(100, Math.trunc(fallbackCount)));

    return commandResult(
        result.status,
        {
            ...result.data,
            stagehand_fallback_count: boundedCount,
        },
        result.reason_code,
    );
}

export function parsePackFromTitle(title: string): {
    quantity: number | null;
    unit: 'g' | 'ml' | 'each' | null;
} {
    const normalized = title.replace(/\s+/g, ' ').trim();
    const massOrVolume = normalized.match(
        /(?:\||\b)(\d+(?:\.\d+)?)\s*(kg|g|ml|l)\b/i,
    );

    if (massOrVolume !== null) {
        const quantity = Number.parseFloat(massOrVolume[1] ?? '');
        const unit = (massOrVolume[2] ?? '').toLowerCase();

        if (Number.isFinite(quantity) && quantity > 0) {
            if (unit === 'kg') {
                return { quantity: quantity * 1000, unit: 'g' };
            }

            if (unit === 'l') {
                return { quantity: quantity * 1000, unit: 'ml' };
            }

            return { quantity, unit: unit as 'g' | 'ml' };
        }
    }

    const multipack = normalized.match(/\b(\d+)\s*(?:pack|pk)\b/i);

    if (multipack !== null) {
        const quantity = Number.parseInt(multipack[1] ?? '', 10);

        return Number.isFinite(quantity) && quantity > 0
            ? { quantity, unit: 'each' }
            : { quantity: null, unit: null };
    }

    if (/\beach\b/i.test(normalized)) {
        return { quantity: 1, unit: 'each' };
    }

    return { quantity: null, unit: null };
}

export function parseMoneyToCents(value: string): number | null {
    const match = value.match(/\$(\d{1,5})(?:\.(\d{2}))?/);

    if (match === null) {
        return null;
    }

    const dollars = Number.parseInt(match[1] ?? '', 10);
    const cents = Number.parseInt(match[2] ?? '0', 10);

    return Number.isFinite(dollars) && Number.isFinite(cents)
        ? dollars * 100 + cents
        : null;
}

export function buildConstraintEvidence(
    text: string,
    constraints: Constraint[],
): ProductCandidate['label_evidence'] {
    const normalized = text.toLowerCase().replace(/\s+/g, ' ');

    return {
        constraints: constraints.map((constraint) => {
            const subject = constraint.subject
                .toLowerCase()
                .replace(/[^a-z0-9 ]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (subject === '') {
                return {
                    constraint_id: constraint.constraint_id,
                    status: 'unknown' as const,
                    source: '',
                };
            }

            const conflictPhrases = [
                `contains ${subject}`,
                `may contain ${subject}`,
                `not suitable for ${subject}`,
            ];
            const compatiblePhrases = [
                `${subject} free`,
                `free from ${subject}`,
                `suitable for ${subject}`,
            ];

            if (conflictPhrases.some((phrase) => normalized.includes(phrase))) {
                return {
                    constraint_id: constraint.constraint_id,
                    status: 'conflict' as const,
                    source: 'coles_product_label',
                };
            }

            if (
                compatiblePhrases.some((phrase) => normalized.includes(phrase))
            ) {
                return {
                    constraint_id: constraint.constraint_id,
                    status: 'compatible' as const,
                    source: 'coles_product_label',
                };
            }

            return {
                constraint_id: constraint.constraint_id,
                status: 'unknown' as const,
                source: '',
            };
        }),
    };
}

async function probeAuthentication(
    page: StagehandPage,
): Promise<WorkerCommandResult> {
    await ensureColesPage(page);
    const state = await page.evaluate(() => {
        const labels = Array.from(
            document.querySelectorAll('button, a'),
            (element) =>
                `${element.textContent ?? ''} ${element.getAttribute('aria-label') ?? ''}`
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase(),
        );

        return {
            showsLogin: labels.some((label) =>
                label.includes('log in / sign up'),
            ),
            showsAccount: labels.some(
                (label) =>
                    label.includes('my account') ||
                    label.includes('account menu') ||
                    label.includes('log out') ||
                    /^hi[, ]/.test(label),
            ),
        };
    });

    if (state.showsLogin || !state.showsAccount) {
        return commandResult(
            'blocked',
            { authenticated: false },
            state.showsLogin
                ? 'authentication_required'
                : 'authentication_unverified',
        );
    }

    return commandResult('succeeded', { authenticated: true });
}

async function searchProducts(
    page: StagehandPage,
    payload: Record<string, unknown>,
): Promise<WorkerCommandResult> {
    const requirements = parseSearchRequirements(payload.requirements);

    if (requirements === null) {
        return commandResult('failed', {}, 'invalid_search_requirements');
    }

    const candidates: ProductCandidate[] = [];
    const attempts: Array<{
        requirement_id: number;
        query: string;
        candidate_skus: string[];
    }> = [];

    for (const requirement of requirements) {
        const requirementCandidates = new Map<string, ProductCandidate>();

        for (const query of requirement.queries.slice(0, MAX_QUERIES)) {
            await navigateAllowed(
                page,
                `${COLES_BASE_URL}/search/products?q=${encodeURIComponent(query)}`,
            );
            const discovered = await extractSearchCards(page);
            const candidateSkus: string[] = [];

            for (const card of discovered) {
                if (
                    requirement.exact_sku !== null &&
                    card.sku !== requirement.exact_sku
                ) {
                    continue;
                }

                requirementCandidates.set(card.sku, {
                    requirement_id: requirement.requirement_id,
                    ...card,
                    label_evidence: buildConstraintEvidence(
                        card.title,
                        requirement.constraints,
                    ),
                });
                candidateSkus.push(card.sku);

                if (
                    requirementCandidates.size >= MAX_CANDIDATES_PER_REQUIREMENT
                ) {
                    break;
                }
            }

            attempts.push({
                requirement_id: requirement.requirement_id,
                query,
                candidate_skus: [...new Set(candidateSkus)],
            });

            if (
                requirementCandidates.size >= MAX_CANDIDATES_PER_REQUIREMENT ||
                (requirement.exact_sku !== null &&
                    requirementCandidates.has(requirement.exact_sku))
            ) {
                break;
            }
        }

        if (requirement.constraints.length > 0) {
            for (const candidate of requirementCandidates.values()) {
                await navigateAllowed(
                    page,
                    new URL(candidate.product_path, COLES_BASE_URL).toString(),
                );
                const labelText = await page.locator('main').innerText();
                candidate.label_evidence = buildConstraintEvidence(
                    labelText,
                    requirement.constraints,
                );
            }
        }

        candidates.push(...requirementCandidates.values());
    }

    return commandResult('succeeded', {
        candidates: candidates.sort((left, right) => {
            if (left.requirement_id !== right.requirement_id) {
                return left.requirement_id - right.requirement_id;
            }

            return left.sku.localeCompare(right.sku);
        }),
        attempts: attempts.map((attempt) => ({
            requirement_id: attempt.requirement_id,
            query: attempt.query,
            result_count: attempt.candidate_skus.length,
            eligible_result_count: attempt.candidate_skus.filter((sku) => {
                const candidate = candidates.find(
                    (item) =>
                        item.requirement_id === attempt.requirement_id &&
                        item.sku === sku,
                );

                return candidate !== undefined && workerCandidateEligible(candidate);
            }).length,
            reason_code:
                attempt.candidate_skus.length === 0 ? 'no_results' : null,
        })),
    });
}

async function extractSearchCards(
    page: StagehandPage,
): Promise<Array<Omit<ProductCandidate, 'requirement_id' | 'label_evidence'>>> {
    const rawCards = await page.evaluate(() => {
        const anchors = Array.from(
            document.querySelectorAll<HTMLAnchorElement>(
                'a[href*="/product/"]',
            ),
        );

        return anchors.slice(0, 80).map((anchor) => {
            const container =
                anchor.closest(
                    'article, li, [data-testid*="product" i], [class*="product" i]',
                ) ?? anchor.parentElement;
            const text = (container?.textContent ?? anchor.textContent ?? '')
                .replace(/\s+/g, ' ')
                .trim();
            const unavailable = /out of stock|unavailable/i.test(text);
            const addControl = Array.from(
                container?.querySelectorAll('button') ?? [],
            ).some((button) =>
                /\badd(?: to trolley)?\b/i.test(button.textContent ?? ''),
            );

            return {
                href: anchor.href,
                title: (anchor.textContent ?? '').replace(/\s+/g, ' ').trim(),
                text,
                available: !unavailable && addControl,
            };
        });
    });
    const cards = new Map<
        string,
        Omit<ProductCandidate, 'requirement_id' | 'label_evidence'>
    >();

    for (const raw of rawCards) {
        const url = safeColesUrl(raw.href);
        const sku = url?.pathname.match(/-(\d{5,12})\/?$/)?.[1];

        if (
            url === null ||
            sku === undefined ||
            raw.title === '' ||
            cards.has(sku)
        ) {
            continue;
        }

        const pack = parsePackFromTitle(raw.title);
        const priceCents = parseMoneyToCents(raw.text);
        const semanticKey = raw.title
            .toLowerCase()
            .replace(/\|\s*\d+(?:\.\d+)?\s*(?:kg|g|ml|l)\b/gi, '')
            .replace(/\b\d+\s*(?:pack|pk)\b/gi, '')
            .replace(/\s+/g, ' ')
            .trim();
        const attributes = retailerAttributes(
            raw.title,
            raw.title.split(/\s+/)[0] ?? null,
        );

        cards.set(sku, {
            sku,
            title: raw.title,
            brand: raw.title.split(/\s+/)[0] ?? null,
            ...attributes,
            semantic_key: semanticKey,
            origin_host: url.hostname.toLowerCase(),
            product_path: `${url.pathname}${url.search}`,
            pack_quantity: pack.quantity,
            pack_unit: pack.unit,
            price_cents: priceCents,
            available: raw.available,
            restricted_product:
                /\/(?:liquor|tobacco)\//i.test(url.pathname) ||
                /\b(?:alcohol|tobacco|cigarette|vape)\b/i.test(raw.title),
        });
    }

    return [...cards.values()];
}

export function retailerAttributes(
    title: string,
    brand: string | null,
): Pick<
    ProductCandidate,
    'is_home_brand' | 'is_organic' | 'attribute_evidence'
> {
    return {
        is_home_brand:
            brand?.trim().toLowerCase() === 'coles' ||
            /^coles\b/i.test(title.trim()),
        is_organic: /\borganic\b/i.test(title),
        attribute_evidence: {
            home_brand: { source: 'coles_search_card' },
            organic: { source: 'coles_search_card' },
        },
    };
}

function workerCandidateEligible(candidate: ProductCandidate): boolean {
    return (
        ALLOWED_HOSTS.has(candidate.origin_host) &&
        /^[A-Za-z0-9._-]+$/.test(candidate.sku) &&
        candidate.available &&
        !candidate.restricted_product &&
        candidate.pack_quantity !== null &&
        candidate.pack_quantity > 0 &&
        candidate.pack_unit !== null &&
        candidate.price_cents !== null &&
        candidate.price_cents > 0 &&
        candidate.label_evidence.constraints.every(
            (constraint) =>
                constraint.status === 'compatible' && constraint.source !== '',
        )
    );
}

async function inspectBasket(
    page: StagehandPage,
    stagehand: Stagehand,
): Promise<BasketInspection> {
    await ensureColesPage(page);
    await openTrolley(page, stagehand);
    const inspection = await page.evaluate(() => {
        const candidates = Array.from(
            document.querySelectorAll<HTMLElement>(
                '[role="dialog"], aside, [data-testid*="trolley" i], [data-testid*="cart" i]',
            ),
        );
        const root =
            candidates.find((candidate) =>
                /\b(trolley|basket)\b/i.test(candidate.textContent ?? ''),
            ) ?? null;

        if (root === null) {
            const bodyText = document.body.textContent ?? '';

            return /your trolley is empty|trolley is empty/i.test(bodyText)
                ? { lines: [], totalText: '$0.00', found: true }
                : { lines: [], totalText: '', found: false };
        }

        const links = Array.from(
            root.querySelectorAll<HTMLAnchorElement>('a[href*="/product/"]'),
        );
        const lines = links.map((link) => {
            const container =
                link.closest(
                    'article, li, [data-testid*="item" i], [data-testid*="product" i]',
                ) ?? link.parentElement;
            const text = (container?.textContent ?? '')
                .replace(/\s+/g, ' ')
                .trim();
            const quantityInput = container?.querySelector<HTMLInputElement>(
                'input[type="number"], input[aria-label*="quantity" i]',
            );
            const quantityLabel = Array.from(
                container?.querySelectorAll('button, [aria-label]') ?? [],
            )
                .map(
                    (element) =>
                        `${element.getAttribute('aria-label') ?? ''} ${element.textContent ?? ''}`,
                )
                .join(' ');
            const prices = [...text.matchAll(/\$(\d{1,5}(?:\.\d{2})?)/g)].map(
                (match) => match[0],
            );

            return {
                href: link.href,
                title: (link.textContent ?? '').replace(/\s+/g, ' ').trim(),
                quantity:
                    quantityInput?.value ??
                    quantityLabel.match(
                        /(?:quantity|in (?:your )?trolley)\D{0,8}(\d+)/i,
                    )?.[1] ??
                    '1',
                unitPrice: prices[0] ?? '',
                linePrice: prices.at(-1) ?? '',
            };
        });
        const rootText = (root.textContent ?? '').replace(/\s+/g, ' ');
        const totalText =
            rootText.match(
                /(?:trolley total|basket total|subtotal)\D{0,20}(\$\d{1,5}(?:\.\d{2})?)/i,
            )?.[1] ?? '';

        return { lines, totalText, found: true };
    });

    if (!inspection.found) {
        throw new Error('trolley_not_found');
    }

    const lines = new Map<string, BasketLine>();

    for (const rawLine of inspection.lines) {
        const url = safeColesUrl(rawLine.href);
        const sku = url?.pathname.match(/-(\d{5,12})\/?$/)?.[1];
        const quantity = Number.parseInt(rawLine.quantity, 10);

        if (
            sku === undefined ||
            rawLine.title === '' ||
            !Number.isFinite(quantity) ||
            quantity < 1
        ) {
            throw new Error('invalid_trolley_line');
        }

        const unitPriceCents = parseMoneyToCents(rawLine.unitPrice);
        const linePriceCents =
            parseMoneyToCents(rawLine.linePrice) ??
            (unitPriceCents === null ? null : unitPriceCents * quantity);

        lines.set(sku, {
            sku,
            title: rawLine.title,
            absolute_quantity: quantity,
            unit_price_cents: unitPriceCents,
            line_price_cents: linePriceCents,
        });
    }

    return {
        lines: [...lines.values()].sort((left, right) =>
            left.sku.localeCompare(right.sku),
        ),
        retailer_total_cents: parseMoneyToCents(inspection.totalText),
    };
}

async function ensureBasketEmpty(
    page: StagehandPage,
    stagehand: Stagehand,
    payload: Record<string, unknown>,
): Promise<WorkerCommandResult> {
    let inspection = await inspectBasket(page, stagehand);
    const checksumResult = validateExpectedBasketChecksum(payload, inspection);

    if (checksumResult !== null) {
        return checksumResult;
    }

    let attempts = 0;

    while (inspection.lines.length > 0 && attempts < MAX_BASKET_MUTATIONS) {
        const target = inspection.lines[0];

        if (target === undefined) {
            break;
        }

        const clicked = await clickBasketControl(page, target.sku, 'remove');

        if (!clicked) {
            const recovered = await observeAndActSafeControl(
                stagehand,
                `Find the remove button for ${target.title} in the open trolley. Do not choose checkout, payment, fulfilment, or order controls.`,
                [
                    'remove',
                    ...target.title.toLowerCase().split(/\s+/).slice(0, 2),
                ],
            );

            if (!recovered) {
                return commandResult('blocked', {}, 'trolley_control_moved');
            }
        }

        attempts += 1;
        await page.waitForTimeout(350);
        inspection = await inspectBasket(page, stagehand);
    }

    if (inspection.lines.length > 0) {
        return commandResult('failed', {}, 'trolley_clear_limit_reached');
    }

    return commandResult('succeeded', {
        observed_line_count: 0,
        basket_checksum: canonicalChecksum(inspection),
    });
}

async function ensureBasketLine(
    page: StagehandPage,
    stagehand: Stagehand,
    payload: Record<string, unknown>,
): Promise<WorkerCommandResult> {
    const productId =
        typeof payload.product_id === 'string' ? payload.product_id : '';
    const targetQuantity =
        typeof payload.absolute_quantity === 'number'
            ? Math.trunc(payload.absolute_quantity)
            : Number.NaN;

    if (
        !/^[A-Za-z0-9._-]+$/.test(productId) ||
        !Number.isFinite(targetQuantity) ||
        targetQuantity < 1 ||
        targetQuantity > 99
    ) {
        return commandResult('failed', {}, 'invalid_absolute_quantity');
    }

    let inspection = await inspectBasket(page, stagehand);
    const checksumResult = validateExpectedBasketChecksum(payload, inspection);

    if (checksumResult !== null) {
        return checksumResult;
    }

    let line = inspection.lines.find(
        (candidate) => candidate.sku === productId,
    );

    if (line === undefined) {
        const added = await addExactProduct(page, stagehand, productId);

        if (!added) {
            return commandResult('blocked', {}, 'exact_product_unavailable');
        }

        await page.waitForTimeout(500);
        inspection = await inspectBasket(page, stagehand);
        line = inspection.lines.find(
            (candidate) => candidate.sku === productId,
        );
    }

    let attempts = 0;

    while (
        line !== undefined &&
        line.absolute_quantity !== targetQuantity &&
        attempts < MAX_BASKET_MUTATIONS
    ) {
        const direction =
            line.absolute_quantity < targetQuantity ? 'increase' : 'decrease';
        const clicked = await clickBasketControl(page, productId, direction);

        if (!clicked) {
            return commandResult('blocked', {}, 'quantity_control_moved');
        }

        attempts += 1;
        await page.waitForTimeout(300);
        inspection = await inspectBasket(page, stagehand);
        line = inspection.lines.find(
            (candidate) => candidate.sku === productId,
        );
    }

    if (line?.absolute_quantity !== targetQuantity) {
        return commandResult('failed', {}, 'absolute_quantity_not_reached');
    }

    return commandResult('succeeded', {
        product_id: productId,
        absolute_quantity: targetQuantity,
        basket_checksum: canonicalChecksum(inspection),
    });
}

export function validateExpectedBasketChecksum(
    payload: Record<string, unknown>,
    inspection: BasketInspection,
): WorkerCommandResult | null {
    const expectedChecksum = payload.expected_checksum;

    if (
        typeof expectedChecksum !== 'string' ||
        !/^[a-f0-9]{64}$/i.test(expectedChecksum)
    ) {
        return commandResult('failed', {}, 'invalid_expected_checksum');
    }

    if (canonicalChecksum(inspection) !== expectedChecksum) {
        return commandResult('uncertain', {}, 'concurrent_basket_change');
    }

    return null;
}

async function addExactProduct(
    page: StagehandPage,
    stagehand: Stagehand,
    productId: string,
): Promise<boolean> {
    await navigateAllowed(
        page,
        `${COLES_BASE_URL}/search/products?q=${encodeURIComponent(productId)}`,
    );
    const products = await extractSearchCards(page);
    const exact = products.find((candidate) => candidate.sku === productId);

    if (exact === undefined || !exact.available || exact.restricted_product) {
        return false;
    }

    await navigateAllowed(
        page,
        new URL(exact.product_path, COLES_BASE_URL).toString(),
    );
    const clicked = await page.evaluate(() => {
        const buttons = Array.from(
            document.querySelectorAll<HTMLButtonElement>('main button'),
        );
        const addButton = buttons.find((button) => {
            const label =
                `${button.textContent ?? ''} ${button.getAttribute('aria-label') ?? ''}`
                    .replace(/\s+/g, ' ')
                    .trim();

            return (
                !button.disabled &&
                /^(?:add|add to trolley)(?:\s|$)/i.test(label) &&
                !/checkout|payment|order|fulfil/i.test(label)
            );
        });

        if (addButton === undefined) {
            return false;
        }

        addButton.click();

        return true;
    });

    if (clicked) {
        return true;
    }

    return observeAndActSafeControl(
        stagehand,
        'Find the Add to trolley button for the single product on this product detail page. Do not choose checkout, payment, fulfilment, or order controls.',
        ['add', 'trolley'],
    );
}

async function openTrolley(
    page: StagehandPage,
    stagehand: Stagehand,
): Promise<void> {
    const alreadyOpen = await page.evaluate(() =>
        Array.from(
            document.querySelectorAll<HTMLElement>(
                '[role="dialog"], aside, [data-testid*="trolley" i], [data-testid*="cart" i]',
            ),
        ).some(
            (element) =>
                element.offsetParent !== null &&
                /\b(trolley|basket)\b/i.test(element.textContent ?? ''),
        ),
    );

    if (alreadyOpen) {
        return;
    }

    const clicked = await page.evaluate(() => {
        const controls = Array.from(
            document.querySelectorAll<HTMLElement>('header button, header a'),
        );
        const trolley = controls.find((control) => {
            const label =
                `${control.textContent ?? ''} ${control.getAttribute('aria-label') ?? ''}`
                    .replace(/\s+/g, ' ')
                    .trim();

            return (
                /\b(trolley|basket)\b/i.test(label) ||
                /^\$\d+(?:\.\d{2})?$/.test(label)
            );
        });

        if (trolley === undefined) {
            return false;
        }

        trolley.click();

        return true;
    });

    if (
        !clicked &&
        !(await observeAndActSafeControl(
            stagehand,
            'Find the header button that opens the current trolley or basket. Do not choose checkout, payment, fulfilment, or order controls.',
            ['trolley', 'basket'],
            true,
        ))
    ) {
        throw new Error('trolley_control_not_found');
    }

    await page.waitForTimeout(350);
}

async function clickBasketControl(
    page: StagehandPage,
    sku: string,
    direction: 'remove' | 'increase' | 'decrease',
): Promise<boolean> {
    return page.evaluate(
        ({ targetSku, targetDirection }) => {
            const link = Array.from(
                document.querySelectorAll<HTMLAnchorElement>(
                    'a[href*="/product/"]',
                ),
            ).find(
                (candidate) =>
                    candidate.href.match(/-(\d{5,12})\/?$/)?.[1] === targetSku,
            );
            const container =
                link?.closest(
                    'article, li, [data-testid*="item" i], [data-testid*="product" i]',
                ) ?? link?.parentElement;

            if (container === undefined || container === null) {
                return false;
            }

            const controls = Array.from(
                container.querySelectorAll<HTMLButtonElement>('button'),
            );
            const control = controls.find((button) => {
                const label =
                    `${button.textContent ?? ''} ${button.getAttribute('aria-label') ?? ''}`
                        .replace(/\s+/g, ' ')
                        .trim()
                        .toLowerCase();

                if (targetDirection === 'remove') {
                    return /\b(remove|delete)\b/.test(label);
                }

                if (targetDirection === 'increase') {
                    return (
                        /\b(increase|add one|plus)\b/.test(label) ||
                        label === '+'
                    );
                }

                return (
                    /\b(decrease|remove one|minus)\b/.test(label) ||
                    label === '−' ||
                    label === '-'
                );
            });

            if (control === undefined || control.disabled) {
                return false;
            }

            control.click();

            return true;
        },
        { targetSku: sku, targetDirection: direction },
    );
}

async function observeAndActSafeControl(
    stagehand: Stagehand,
    instruction: string,
    requiredTokens: string[],
    anyToken = false,
): Promise<boolean> {
    stagehandFallbackCount += 1;

    try {
        const actions = await stagehand.observe(instruction, {
            timeout: 20_000,
            serverCache: false,
        });
        const action = actions.find((candidate) => {
            const description = candidate.description.toLowerCase();
            const hasRequiredTokens = anyToken
                ? requiredTokens.some((token) => description.includes(token))
                : requiredTokens.every((token) => description.includes(token));

            return (
                candidate.method === 'click' &&
                hasRequiredTokens &&
                !/checkout|payment|pay now|place order|fulfilment|delivery slot/.test(
                    description,
                )
            );
        });

        if (action === undefined) {
            return false;
        }

        await stagehand.act(action);

        return true;
    } catch {
        return false;
    }
}

async function ensureColesPage(page: StagehandPage): Promise<void> {
    if (safeColesUrl(page.url()) === null) {
        await navigateAllowed(page, COLES_BASE_URL);
    }
}

async function navigateAllowed(
    page: StagehandPage,
    url: string,
): Promise<void> {
    const safe = safeColesUrl(url);

    if (safe === null) {
        throw new Error('disallowed_navigation');
    }

    await page.goto(safe.toString(), {
        waitUntil: 'domcontentloaded',
        timeoutMs: 45_000,
    });
    await page.waitForTimeout(300);

    if (safeColesUrl(page.url()) === null) {
        throw new Error('unexpected_origin');
    }
}

function safeColesUrl(value: string): URL | null {
    try {
        const url = new URL(value, COLES_BASE_URL);

        return url.protocol === 'https:' && ALLOWED_HOSTS.has(url.hostname)
            ? url
            : null;
    } catch {
        return null;
    }
}

function parseSearchRequirements(value: unknown): SearchRequirement[] | null {
    if (!Array.isArray(value) || value.length > 120) {
        return null;
    }

    const requirements: SearchRequirement[] = [];

    for (const item of value) {
        if (!isRecord(item)) {
            return null;
        }

        const requirementId =
            typeof item.requirement_id === 'number'
                ? Math.trunc(item.requirement_id)
                : Number.NaN;
        const queries = Array.isArray(item.queries)
            ? item.queries
                  .filter(
                      (query): query is string =>
                          typeof query === 'string' &&
                          query.trim() !== '' &&
                          query.length <= 180,
                  )
                  .slice(0, MAX_QUERIES)
            : [];
        const constraints = Array.isArray(item.constraints)
            ? item.constraints
                  .filter(isRecord)
                  .map((constraint) => ({
                      constraint_id:
                          typeof constraint.constraint_id === 'number'
                              ? Math.trunc(constraint.constraint_id)
                              : 0,
                      kind:
                          typeof constraint.kind === 'string'
                              ? constraint.kind
                              : '',
                      subject:
                          typeof constraint.subject === 'string'
                              ? constraint.subject
                              : '',
                  }))
                  .filter(
                      (constraint) =>
                          constraint.constraint_id > 0 &&
                          [
                              'allergy',
                              'medical',
                              'dietary',
                              'religious',
                          ].includes(constraint.kind) &&
                          constraint.subject.trim() !== '',
                  )
            : [];
        const exactSku =
            typeof item.exact_sku === 'string' ? item.exact_sku : null;

        if (
            !Number.isFinite(requirementId) ||
            requirementId < 1 ||
            queries.length === 0
        ) {
            return null;
        }

        requirements.push({
            requirement_id: requirementId,
            queries,
            constraints,
            exact_sku: exactSku,
        });
    }

    return requirements;
}
