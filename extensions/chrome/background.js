importScripts('policy.js');

const POLL_ALARM = 'chef-automation-poll';
let pollInProgress = false;

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(POLL_ALARM, { periodInMinutes: 0.5 });
  void setIndicator(false);
});

chrome.runtime.onStartup.addListener(() => {
  chrome.alarms.create(POLL_ALARM, { periodInMinutes: 0.5 });
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === POLL_ALARM) void poll();
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message.type === 'poll-now') {
    void poll().then(() => sendResponse({ ok: true }));
    return true;
  }
});

async function settings() {
  return chrome.storage.local.get([
    'apiBase',
    'connectionToken',
    'activeRunUuid',
    'activeTabId',
  ]);
}

async function api(path, options = {}) {
  const config = await settings();
  const response = await fetch(`${config.apiBase}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Chef-Connection-Token': config.connectionToken,
      ...(options.headers || {}),
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.message || `Chef returned ${response.status}.`);
  }

  return response.json();
}

async function poll() {
  if (pollInProgress) return;

  pollInProgress = true;

  try {
    await pollOnce();
  } finally {
    pollInProgress = false;
    await setIndicator(false).catch(() => {});
  }
}

async function pollOnce() {
  const config = await settings();
  if (!config.apiBase || !config.connectionToken || !config.activeRunUuid || !config.activeTabId) return;

  const selectedTabId = Number(config.activeTabId);
  const selectedTab = await chrome.tabs.get(selectedTabId).catch(() => null);
  if (!selectedTab?.active) {
    await setIndicator(false);
    return;
  }

  // Capture before claiming work so a visibility race cannot strand a server step.
  // The image stays local unless a step is actually returned below.
  const beforeScreenshot = await captureSelectedTab(selectedTabId);
  const payload = await api(`/api/extension/runs/${encodeURIComponent(config.activeRunUuid)}/steps/next`);
  if (!payload.step) {
    await setIndicator(false);
    return;
  }

  const step = payload.step;
  const tab = await chrome.tabs.get(selectedTabId);
  let result = { ok: true, executed: 0 };

  try {
    ChefExtensionPolicy.assertStep(step, tab.url, config.activeTabId, config.activeRunUuid);
    await setIndicator(true);

    for (const action of step.actions) {
      await assertSelectedTabVisible(selectedTabId);

      if (!await stepMayContinue(step.id)) {
        await setIndicator(false);
        return;
      }

      const completed = await executeAction(
        selectedTabId,
        action,
        async () => {
          await assertSelectedTabVisible(selectedTabId);
          return stepMayContinue(step.id);
        },
      );
      if (!completed) {
        await setIndicator(false);
        return;
      }

      result.executed += 1;

      const afterAction = await chrome.tabs.get(selectedTabId);
      if (ChefExtensionPolicy.originOf(afterAction.url) !== step.allowed_origin) {
        throw new Error('The retailer tab navigated outside its approved origin.');
      }

      if (ChefExtensionPolicy.requiresTakeover(afterAction.url)) {
        result.stopped_for_takeover = true;
        break;
      }
    }
  } catch (error) {
    result = { ok: false, executed: result.executed, error: String(error.message || error) };
  }

  let currentUrl = tab.url;
  let screenshot = beforeScreenshot;

  try {
    const currentTab = await chrome.tabs.get(selectedTabId);
    currentUrl = currentTab.url;
    screenshot = await captureSelectedTab(selectedTabId);
  } catch (error) {
    result = {
      ok: false,
      executed: result.executed,
      error: 'Chef stopped because the selected retailer tab was no longer visible.',
    };
  }

  try {
    await api(`/api/extension/steps/${step.id}/result`, {
      method: 'POST',
      body: JSON.stringify({
        current_url: currentUrl,
        screenshot,
        result,
      }),
    });
  } finally {
    await setIndicator(false);
  }
}

async function stepMayContinue(stepId) {
  const state = await api(`/api/extension/steps/${stepId}/control`);

  return state.continue === true;
}

async function captureSelectedTab(tabId) {
  await assertSelectedTabVisible(tabId);
  const tab = await chrome.tabs.get(tabId);

  return chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
}

async function assertSelectedTabVisible(tabId) {
  const tab = await chrome.tabs.get(tabId);
  const [activeTab] = await chrome.tabs.query({ active: true, windowId: tab.windowId });
  ChefExtensionPolicy.assertVisibleTab(tab, activeTab, tabId);
}

async function executeAction(tabId, action, mayContinue) {
  if (action.type === 'wait') {
    let remaining = Math.min(Number(action.duration_ms || 1000), 10000);

    while (remaining > 0) {
      const duration = Math.min(remaining, 500);
      await new Promise((resolve) => setTimeout(resolve, duration));
      remaining -= duration;

      if (!await mayContinue()) return false;
    }

    return true;
  }

  if (action.type === 'screenshot') return true;

  const [{ result }] = await chrome.scripting.executeScript({
    target: { tabId },
    world: 'MAIN',
    func: runPageAction,
    args: [action],
  });

  if (!result?.ok) throw new Error(result?.error || 'The page rejected the action.');

  return true;
}

function runPageAction(action) {
  const point = () => document.elementFromPoint(Number(action.x || 0), Number(action.y || 0));
  const blockedControl = (element) => {
    const control = element?.closest?.('a, button, [role="button"], input[type="submit"]');
    if (!control) return false;
    const descriptor = [
      control.textContent,
      control.getAttribute('aria-label'),
      control.getAttribute('href'),
      control.getAttribute('formaction'),
      control.getAttribute('value'),
    ].filter(Boolean).join(' ').toLowerCase();

    const href = control.getAttribute('href') || control.getAttribute('formaction') || '';
    const safeCartReview = /view\s+(trolley|cart)(\s+button)?(\s+and\s+checkout)?|your\s+cart/.test(descriptor)
      && !/\/checkout|\/payment|\/login|\/sign-in|\/address|\/delivery-slot/.test(href.toLowerCase());

    if (safeCartReview) return false;

    return /checkout|place\s+order|submit\s+order|payment|pay\s+now|sign[ -]?in|log[ -]?in|delivery\s+(slot|time)|change\s+address/.test(descriptor);
  };
  const emitInput = (element) => {
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  };

  try {
    switch (action.type) {
      case 'click':
        if (blockedControl(point())) throw new Error('Chef blocked a checkout or account control.');
        point()?.click();
        break;
      case 'double_click':
        if (blockedControl(point())) throw new Error('Chef blocked a checkout or account control.');
        point()?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        break;
      case 'scroll':
        window.scrollBy({ left: Number(action.scroll_x || 0), top: Number(action.scroll_y || 0), behavior: 'instant' });
        break;
      case 'type': {
        const element = point() || document.activeElement;
        if (!(element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLElement)) throw new Error('No editable element at the requested point.');
        element.focus();
        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
          const prototype = element instanceof HTMLInputElement ? HTMLInputElement.prototype : HTMLTextAreaElement.prototype;
          Object.getOwnPropertyDescriptor(prototype, 'value')?.set?.call(element, String(action.text || ''));
        } else {
          element.textContent = String(action.text || '');
        }
        emitInput(element);
        break;
      }
      case 'keypress': {
        const element = document.activeElement || document.body;
        const keys = Array.isArray(action.keys) ? action.keys : [action.key || 'Enter'];
        if (keys.includes('Enter') && blockedControl(element)) throw new Error('Chef blocked form submission at a checkout or account boundary.');
        for (const key of keys) {
          element.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
          element.dispatchEvent(new KeyboardEvent('keyup', { key, bubbles: true }));
        }
        break;
      }
      case 'move':
        point()?.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
        break;
      case 'drag': {
        const path = action.path || [];
        const start = path[0];
        const end = path[path.length - 1];
        const element = start ? document.elementFromPoint(start.x, start.y) : null;
        if (!element || !end) throw new Error('The drag path is incomplete.');
        element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: start.x, clientY: start.y }));
        element.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: end.x, clientY: end.y }));
        element.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: end.x, clientY: end.y }));
        break;
      }
      default:
        throw new Error('Unsupported page action.');
    }

    return { ok: true };
  } catch (error) {
    return { ok: false, error: String(error.message || error) };
  }
}

async function setIndicator(active) {
  await chrome.action.setBadgeText({ text: active ? 'RUN' : '' });
  await chrome.action.setBadgeBackgroundColor({ color: active ? '#9F3A24' : '#66735F' });
  await chrome.action.setTitle({
    title: active ? 'Chef is controlling the selected retailer tab' : 'Chef retailer handoff',
  });
}
