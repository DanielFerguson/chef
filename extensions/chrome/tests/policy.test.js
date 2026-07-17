const test = require('node:test');
const assert = require('node:assert/strict');
const policy = require('../policy.js');

test('accepts only the two retailer origins and the selected tab', () => {
  const step = {
    allowed_origin: 'https://www.woolworths.com.au',
    tab_id: '42',
    actions: [{ type: 'click', x: 10, y: 10 }],
  };

  assert.doesNotThrow(() => policy.assertStep(step, 'https://www.woolworths.com.au/shop/cart', 42));
  assert.throws(() => policy.assertStep(step, 'https://example.com', 42));
  assert.throws(() => policy.assertStep(step, 'https://www.woolworths.com.au/shop/cart', 99));
  assert.throws(() => policy.assertStep(step, 'https://www.woolworths.com.au/checkout', 42));
});

test('rejects malformed local action payloads before they reach the page', () => {
  assert.throws(() => policy.assertStep({
    allowed_origin: 'https://www.coles.com.au',
    tab_id: '7',
    actions: [{ type: 'click', x: 'not-a-number', y: 10 }],
  }, 'https://www.coles.com.au/browse', 7));

  assert.throws(() => policy.assertStep({
    allowed_origin: 'https://www.coles.com.au',
    tab_id: '7',
    actions: [{ type: 'type', text: 'x'.repeat(2001) }],
  }, 'https://www.coles.com.au/browse', 7));
});

test('rejects server commands outside the local action allowlist', () => {
  assert.throws(() => policy.assertStep({
    allowed_origin: 'https://www.coles.com.au',
    tab_id: '7',
    actions: [{ type: 'navigate', url: 'https://example.com' }],
  }, 'https://www.coles.com.au/browse', 7));
});

test('identifies checkout, payment, authentication, address, and delivery paths', () => {
  for (const path of ['checkout', 'payment', 'login', 'address', 'delivery-slot']) {
    assert.equal(policy.requiresTakeover(`https://www.coles.com.au/${path}`), true);
  }
  assert.equal(policy.requiresTakeover('https://www.coles.com.au/browse/meat'), false);
});
