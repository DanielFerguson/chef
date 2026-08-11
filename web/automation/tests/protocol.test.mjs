import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildConstraintEvidence,
    parseMoneyToCents,
    parsePackFromTitle,
    retailerAttributes,
    validateExpectedBasketChecksum,
    withStagehandFallbackCount,
} from '../dist/coles.js';
import {
    canonicalChecksum,
    parseWorkerRequest,
    RETAILER_COMMANDS,
    RETAILER_PROTOCOL,
} from '../dist/protocol.js';

test('parses the versioned command protocol', () => {
    assert.deepEqual(
        parseWorkerRequest({
            protocol: RETAILER_PROTOCOL,
            operation: 'command.execute',
            context_id: 'ctx_123',
            command: 'inspect_basket',
            payload: {},
        }),
        {
            protocol: RETAILER_PROTOCOL,
            operation: 'command.execute',
            context_id: 'ctx_123',
            session_id: null,
            command: 'inspect_basket',
            payload: {},
        },
    );
});

test('accepts every bounded retailer command fixture', () => {
    const fixtures = [
        ['probe_auth', {}],
        ['search_products', { requirements: [] }],
        ['inspect_basket', {}],
        ['ensure_basket_empty', { expected_checksum: 'a'.repeat(64) }],
        [
            'ensure_basket_line',
            {
                product_id: '3329035',
                absolute_quantity: 2,
                expected_checksum: 'b'.repeat(64),
            },
        ],
        ['release_session', {}],
    ];

    for (const [command, payload] of fixtures) {
        const request = parseWorkerRequest({
            protocol: RETAILER_PROTOCOL,
            operation: 'command.execute',
            context_id: 'ctx_fixture',
            command,
            payload,
        });

        assert.equal(request.command, command);
        assert.deepEqual(request.payload, payload);
    }
});

test('canonical checksum is insensitive to object key order', () => {
    assert.equal(
        canonicalChecksum({ b: 2, a: { z: 1, y: 2 } }),
        canonicalChecksum({ a: { y: 2, z: 1 }, b: 2 }),
    );
});

test('normalises common Australian grocery pack sizes', () => {
    assert.deepEqual(parsePackFromTitle('Pasta Spirals | 500g'), {
        quantity: 500,
        unit: 'g',
    });
    assert.deepEqual(parsePackFromTitle('Milk | 2L'), {
        quantity: 2000,
        unit: 'ml',
    });
    assert.deepEqual(parsePackFromTitle('Eggs 12 pack'), {
        quantity: 12,
        unit: 'each',
    });
    assert.equal(parseMoneyToCents('Special $3.90 Was $4.50'), 390);
});

test('derives home-brand and organic attributes only from retailer card evidence', () => {
    assert.deepEqual(retailerAttributes('Coles Organic Penne 500g', 'Coles'), {
        is_home_brand: true,
        is_organic: true,
        attribute_evidence: {
            home_brand: { source: 'coles_search_card' },
            organic: { source: 'coles_search_card' },
        },
    });
    assert.deepEqual(retailerAttributes('Barilla Penne 500g', 'Barilla'), {
        is_home_brand: false,
        is_organic: false,
        attribute_evidence: {
            home_brand: { source: 'coles_search_card' },
            organic: { source: 'coles_search_card' },
        },
    });
});

test('label evidence stays conservative for explicit constraints', () => {
    const constraints = [
        {
            constraint_id: 4,
            kind: 'allergy',
            subject: 'gluten',
        },
    ];

    assert.deepEqual(
        buildConstraintEvidence(
            'Allergen: Contains Gluten, Wheat',
            constraints,
        ),
        {
            constraints: [
                {
                    constraint_id: 4,
                    status: 'conflict',
                    source: 'coles_product_label',
                },
            ],
        },
    );
    assert.deepEqual(
        buildConstraintEvidence('No claim is shown', constraints),
        {
            constraints: [
                {
                    constraint_id: 4,
                    status: 'unknown',
                    source: '',
                },
            ],
        },
    );
});

test('basket mutations require the checksum from the immediately preceding inspection', () => {
    const inspection = {
        lines: [
            {
                sku: '3329035',
                title: 'Penne Pasta 500g',
                absolute_quantity: 2,
                unit_price_cents: 150,
                line_price_cents: 300,
            },
        ],
        retailer_total_cents: 300,
    };

    assert.equal(
        validateExpectedBasketChecksum(
            { expected_checksum: canonicalChecksum(inspection) },
            inspection,
        ),
        null,
    );
    assert.equal(
        validateExpectedBasketChecksum(
            { expected_checksum: '0'.repeat(64) },
            inspection,
        )?.reason_code,
        'concurrent_basket_change',
    );
    assert.equal(
        validateExpectedBasketChecksum({}, inspection)?.reason_code,
        'invalid_expected_checksum',
    );
});

test('accepts bounded ephemeral session input without adding a basket command', () => {
    const request = parseWorkerRequest({
        protocol: RETAILER_PROTOCOL,
        operation: 'session.input',
        context_id: 'context_1',
        session_id: 'session_1',
        input: {
            kind: 'key',
            value: 'Enter',
        },
    });

    assert.equal(request.operation, 'session.input');
    assert.deepEqual(RETAILER_COMMANDS, [
        'probe_auth',
        'search_products',
        'inspect_basket',
        'ensure_basket_empty',
        'ensure_basket_line',
        'release_session',
    ]);
});

test('records only a bounded Stagehand fallback counter in command data', () => {
    const result = withStagehandFallbackCount(
        {
            protocol: RETAILER_PROTOCOL,
            status: 'succeeded',
            reason_code: null,
            verification_checksum: null,
            data: { observed_line_count: 0 },
        },
        1_000,
    );

    assert.equal(result.data.stagehand_fallback_count, 100);
    assert.equal(result.data.observed_line_count, 0);
    assert.equal(typeof result.verification_checksum, 'string');
});
