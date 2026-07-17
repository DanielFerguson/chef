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

  function assertStep(step, currentUrl, expectedTabId) {
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

    if (!Array.isArray(step.actions) || step.actions.length < 1 || step.actions.length > 25) {
      throw new Error('The server command has an invalid action batch.');
    }

    for (const action of step.actions) {
      if (!ALLOWED_ACTIONS.has(action.type)) {
        throw new Error(`The server command contains a disallowed ${action.type} action.`);
      }

      if (action.type === 'type' && String(action.text || '').length > 2000) {
        throw new Error('The server command contains an oversized typing action.');
      }

      if (['click', 'double_click', 'move'].includes(action.type)
        && (!Number.isFinite(Number(action.x)) || !Number.isFinite(Number(action.y)))) {
        throw new Error(`The server command contains invalid ${action.type} coordinates.`);
      }
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

  const policy = { ALLOWED_ORIGINS, originOf, assertStep, requiresTakeover };
  root.ChefExtensionPolicy = policy;

  if (typeof module !== 'undefined') module.exports = policy;
})(typeof self !== 'undefined' ? self : globalThis);
