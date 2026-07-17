(function (root) {
  const ALLOWED_ORIGINS = new Set([
    'https://www.woolworths.com.au',
    'https://www.coles.com.au',
  ]);
  const ALLOWED_ACTIONS = new Set([
    'click',
    'double_click',
    'scroll',
    'type',
    'wait',
    'keypress',
    'drag',
    'move',
    'screenshot',
  ]);
  const TAKEOVER_PATHS = [
    'checkout',
    'payment',
    'login',
    'sign-in',
    'address',
    'delivery-slot',
  ];

  function originOf(url) {
    try {
      const parsed = new URL(url);
      return parsed.protocol === 'https:' ? parsed.origin : '';
    } catch {
      return '';
    }
  }

  function assertStep(step, currentUrl, expectedTabId, expectedRunUuid) {
    const origin = originOf(currentUrl);

    if (!ALLOWED_ORIGINS.has(origin) || origin !== step.allowed_origin) {
      throw new Error('The selected tab is outside the approved retailer origin.');
    }

    if (requiresTakeover(currentUrl)) {
      throw new Error('Chef cannot execute actions on checkout, payment, authentication, address, or delivery pages.');
    }

    if (String(step.tab_id) !== String(expectedTabId)) {
      throw new Error('The command targets a different browser tab.');
    }

    if (expectedRunUuid && String(step.run_uuid) !== String(expectedRunUuid)) {
      throw new Error('The command belongs to a different Chef cart-preparation run.');
    }

    if (!Array.isArray(step.actions) || step.actions.length < 1 || step.actions.length > 25) {
      throw new Error('The server command has an invalid action batch.');
    }

    for (const action of step.actions) {
      if (!action || typeof action !== 'object' || Array.isArray(action)) {
        throw new Error('The server command contains an invalid action.');
      }

      if (!ALLOWED_ACTIONS.has(action.type)) {
        throw new Error(`The server command contains a disallowed ${action.type} action.`);
      }

      assertAction(action);
    }
  }

  function assertVisibleTab(tab, activeTab, expectedTabId) {
    if (!tab?.active || activeTab?.id !== Number(expectedTabId) || tab.id !== Number(expectedTabId)) {
      throw new Error('Keep the selected retailer tab visible while Chef is preparing the cart.');
    }
  }

  function assertAction(action) {
    const assertNumber = (value, label, minimum = -100000, maximum = 100000) => {
      if (typeof value !== 'number' || !Number.isFinite(value) || value < minimum || value > maximum) {
        throw new Error(`The server command contains an invalid ${label}.`);
      }
    };
    const assertPoint = (point, label) => {
      if (!point || typeof point !== 'object' || Array.isArray(point)) {
        throw new Error(`The server command contains an invalid ${label}.`);
      }
      assertNumber(point.x, `${label} x coordinate`, 0);
      assertNumber(point.y, `${label} y coordinate`, 0);
    };

    if (!action || typeof action !== 'object' || Array.isArray(action)) {
      throw new Error('The server command contains an invalid action.');
    }

    switch (action.type) {
      case 'click':
      case 'double_click':
      case 'move':
        assertPoint(action, `${action.type} point`);
        break;
      case 'scroll':
        assertNumber(action.scroll_x, 'horizontal scroll');
        assertNumber(action.scroll_y, 'vertical scroll');
        if ('x' in action || 'y' in action) assertPoint(action, 'scroll point');
        break;
      case 'type':
        if (typeof action.text !== 'string' || action.text.length > 2000) {
          throw new Error('The server command contains an invalid typing action.');
        }
        if ('x' in action || 'y' in action) assertPoint(action, 'typing point');
        break;
      case 'wait':
        if ('duration_ms' in action) assertNumber(action.duration_ms, 'wait duration', 0, 10000);
        break;
      case 'keypress':
        if (!Array.isArray(action.keys) || action.keys.length < 1 || action.keys.length > 10
          || action.keys.some((key) => typeof key !== 'string' || key.length < 1 || key.length > 50)) {
          throw new Error('The server command contains an invalid keypress action.');
        }
        break;
      case 'drag':
        if (!Array.isArray(action.path) || action.path.length < 2 || action.path.length > 100) {
          throw new Error('The server command contains an invalid drag path.');
        }
        action.path.forEach((point, index) => assertPoint(point, `drag point ${index + 1}`));
        break;
      case 'screenshot':
        break;
      default:
        throw new Error(`The server command contains a disallowed ${action.type} action.`);
    }
  }

  function requiresTakeover(url) {
    try {
      const path = new URL(url).pathname.toLowerCase();
      return TAKEOVER_PATHS.some((part) => path.includes(part));
    } catch {
      return true;
    }
  }

  const policy = { ALLOWED_ORIGINS, originOf, assertStep, assertAction, assertVisibleTab, requiresTakeover };
  root.ChefExtensionPolicy = policy;

  if (typeof module !== 'undefined') module.exports = policy;
})(typeof self !== 'undefined' ? self : globalThis);
