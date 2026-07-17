# Chef retailer handoff extension

This Manifest V3 extension is the local execution surface for Chef's Woolworths
and Coles cart-preparation runs. It receives short-lived Chef connection
credentials, never the permanent OpenAI key, and executes validated actions only
in the retailer tab selected by the household.

## Local installation

1. In Chrome, open `chrome://extensions` and enable **Developer mode**.
2. Choose **Load unpacked** and select this `extensions/chrome` directory.
3. In Chef's Shopping workspace, create a temporary pairing code.
4. Open the extension, use the Chef application origin as **Chef API URL**, and
   enter the pairing code.
5. Start a Woolworths or Coles cart-preparation run in Chef, open that retailer,
   then use **Connect selected retailer tab** in the extension.

`manifest.json` permits `http://localhost/*` and a non-functional example host
for local development. Never distribute that source manifest. Build a
production-only directory with the exact deployed HTTPS origin and release
version:

```bash
npm run build:production -- --origin=https://your-chef-host --version=1.0.0
```

The command rejects localhost and reserved placeholder domains, removes all
development application origins from `dist/manifest.json`, and pre-fills the
same exact origin in the popup. Load and package `dist/`, record its hash in the
beta evidence, and add no other application or retailer origin without a
product and security review.

## Safety boundary

- The run is frozen to one shopping-list revision, retailer, connection, and tab.
- A connection accepts one active run at a time, and every claimed step must
  match the run attached in the extension.
- The extension refuses commands outside its local action allowlist and refuses
  execution when the selected tab is not visible, including between actions and
  throughout waits.
- It stops after any navigation to checkout, authentication, address, delivery,
  or payment paths and blocks page controls that directly request those actions.
- The cart or trolley review control remains available; order submission does
  not.
- Switching tabs during a batch fails the run with the last screenshot from the
  selected retailer tab, so an unrelated tab is never captured.
- Chef expires retained screenshots and stalled execution leases. The household
  can pause, cancel, revoke the connection, or take manual control at any time.
- The extension checks for pause or cancellation between every action and every
  500 ms during a wait. It serializes overlapping polls so one selected tab never
  receives two concurrent Chef batches.

Run the extension checks with:

```bash
npm run check
```
