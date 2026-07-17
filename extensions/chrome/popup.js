const form = document.querySelector('#pairing-form');
const paired = document.querySelector('#paired');
const status = document.querySelector('#status');
const label = document.querySelector('#connection-label');

void refresh();

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  setStatus('Pairing…');

  try {
    const apiBase = document.querySelector('#api-base').value.replace(/\/$/, '');
    const response = await fetch(`${apiBase}/api/extension/connections/claim`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({
        pairing_code: document.querySelector('#pairing-code').value,
        name: document.querySelector('#connection-name').value,
      }),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Pairing failed.');

    await chrome.storage.local.set({
      apiBase,
      connectionToken: body.token,
      connection: body.connection,
    });
    setStatus('Paired. Start a cart in Chef, then select the matching retailer tab.');
    await refresh();
  } catch (error) {
    setStatus(String(error.message || error), true);
  }
});

document.querySelector('#attach-tab').addEventListener('click', async () => {
  try {
    const config = await chrome.storage.local.get(['apiBase', 'connectionToken']);
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab?.id || !ChefExtensionPolicy.ALLOWED_ORIGINS.has(ChefExtensionPolicy.originOf(tab.url))) {
      throw new Error('Open the chosen Woolworths or Coles tab first.');
    }

    const awaiting = await api(config, '/api/extension/runs/awaiting');
    const run = awaiting.runs.find((candidate) => {
      const expected = candidate.retailer.slug === 'coles' ? 'https://www.coles.com.au' : 'https://www.woolworths.com.au';
      return expected === ChefExtensionPolicy.originOf(tab.url);
    });
    if (!run) throw new Error('Start a cart-preparation run for this retailer in Chef first.');

    const screenshot = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
    await api(config, `/api/extension/runs/${run.uuid}/attach`, {
      method: 'POST',
      body: JSON.stringify({ tab_id: String(tab.id), current_url: tab.url, screenshot }),
    });
    await chrome.storage.local.set({ activeRunUuid: run.uuid, activeTabId: tab.id });
    await chrome.runtime.sendMessage({ type: 'poll-now' });
    setStatus('Chef is connected to this tab. Keep the tab open and review progress in Chef.');
  } catch (error) {
    setStatus(String(error.message || error), true);
  }
});

document.querySelector('#disconnect').addEventListener('click', async () => {
  await chrome.storage.local.clear();
  setStatus('Connection forgotten. Revoke it in Chef too if it is still listed.');
  await refresh();
});

async function api(config, path, options = {}) {
  const response = await fetch(`${config.apiBase}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Chef-Connection-Token': config.connectionToken,
    },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `Chef returned ${response.status}.`);
  return body;
}

async function refresh() {
  const config = await chrome.storage.local.get(['apiBase', 'connectionToken', 'connection']);
  const isPaired = Boolean(config.apiBase && config.connectionToken);
  form.hidden = isPaired;
  paired.hidden = !isPaired;
  if (config.connection?.name) label.textContent = config.connection.name;
}

function setStatus(message, error = false) {
  status.textContent = message;
  status.style.color = error ? '#a42816' : '';
}
