import assert from 'node:assert/strict';
import test from 'node:test';

import {
    assertAllowedUrl,
    isFulfilmentOrCheckoutPath,
    isSensitiveHumanOnlyPath,
} from './dist/src/woolworths/browser.js';

test('allows fulfilment, checkout, and order-confirmation surfaces', () => {
    for (const path of [
        '/shop/checkout/cart',
        '/shop/checkout',
        '/shop/checkout/delivery',
        '/shop/pickup',
        '/shop/checkout/order-confirmation',
        '/shop/order-confirmation',
    ]) {
        assert.equal(isFulfilmentOrCheckoutPath(path), true);
        assert.doesNotThrow(() =>
            assertAllowedUrl(`https://www.woolworths.com.au${path}`),
        );
    }
});

test('blocks password, card, and address-change surfaces', () => {
    for (const path of [
        '/shop/securelogin',
        '/shop/myaccount',
        '/shop/checkout/payment',
        '/shop/checkout/address',
        '/shop/checkout/billing',
    ]) {
        assert.equal(isSensitiveHumanOnlyPath(path), true);
        assert.throws(
            () => assertAllowedUrl(`https://www.woolworths.com.au${path}`),
            (error) =>
                error instanceof Error &&
                /sensitive_navigation|human-only Woolworths page/i.test(
                    String(error.message ?? error),
                ),
        );
    }
});

test('still allows product browse pages', () => {
    assert.doesNotThrow(() =>
        assertAllowedUrl(
            'https://www.woolworths.com.au/shop/productdetails/123456',
        ),
    );
});
