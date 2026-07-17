const test = require('node:test');
const assert = require('node:assert/strict');
const policy = require('../policy.js');

test('accepts only the two retailer origins and the selected tab', () => {
  const step = {
    run_uuid: 'run-1',
    allowed_origin: 'https://www.woolworths.com.au',
    tab_id: '42',
    actions: [{ type: 'click', x: 10, y: 10 }],
  };

  assert.doesNotThrow(() => policy.assertStep(step, 'https://www.woolworths.com.au/shop/cart', 42, 'run-1'));
  assert.throws(() => policy.assertStep(step, 'https://example.com', 42));
  assert.throws(() => policy.assertStep(step, 'https://www.woolworths.com.au/shop/cart', 99));
  assert.throws(() => policy.assertStep(step, 'https://www.woolworths.com.au/shop/cart', 42, 'run-2'));
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

test('validates every local computer action payload', () => {
  for (const action of [
    { type: 'click', x: 1, y: 2 },
    { type: 'double_click', x: 1, y: 2 },
    { type: 'move', x: 1, y: 2 },
    { type: 'scroll', x: 1, y: 2, scroll_x: 0, scroll_y: 500 },
    { type: 'type', text: 'milk' },
    { type: 'wait', duration_ms: 500 },
    { type: 'keypress', keys: ['ENTER'] },
    { type: 'drag', path: [{ x: 1, y: 2 }, { x: 3, y: 4 }] },
    { type: 'screenshot' },
  ]) {
    assert.doesNotThrow(() => policy.assertAction(action));
  }

  for (const action of [
    { type: 'scroll', scroll_x: Number.POSITIVE_INFINITY, scroll_y: 10 },
    { type: 'wait', duration_ms: 10001 },
    { type: 'keypress', keys: [] },
    { type: 'drag', path: [{ x: 1, y: 2 }] },
    { type: 'type', text: ['not', 'text'] },
    { type: 'click', x: -1, y: 2 },
  ]) {
    assert.throws(() => policy.assertAction(action));
  }
});

test('requires the selected retailer tab to remain visible', () => {
  assert.doesNotThrow(() => policy.assertVisibleTab({ id: 42, active: true }, { id: 42 }, 42));
  assert.throws(() => policy.assertVisibleTab({ id: 42, active: false }, { id: 99 }, 42));
  assert.throws(() => policy.assertVisibleTab({ id: 42, active: true }, { id: 99 }, 42));
});

test('identifies checkout, payment, authentication, address, and delivery paths', () => {
  for (const path of ['checkout', 'payment', 'login', 'address', 'delivery-slot']) {
    assert.equal(policy.requiresTakeover(`https://www.coles.com.au/${path}`), true);
  }
  assert.equal(policy.requiresTakeover('https://www.coles.com.au/browse/meat'), false);
});
