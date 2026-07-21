# Browserbase Woolworths Cart Preparation Plan

## Summary

Implement a Woolworths-only M6 vertical slice that turns a current, approved shopping-list revision into a verified cart using a persistent Browserbase login and OpenAI computer use. Laravel remains the system of record and policy authority; an in-repo TypeScript worker controls Browserbase over CDP. Checkout and payment remain human actions in the user’s normal Woolworths app or browser.

Success requires:

- Just-in-time Woolworths connection after the shopping list is ready.
- Human password/MFA entry through a recording-disabled Browserbase Live View.
- Persistent authentication, deterministic verification, and safe reauthentication.
- A frozen shopping revision and idempotent item-level cart preparation.
- Explicit handling of any pre-existing Woolworths cart.
- Verification after every cart mutation and full final reconciliation.
- The resulting cart appearing in the same account’s normal Woolworths app/site.
- No model access to credentials, payment, address, checkout, or unrestricted browser actions.

## Architecture and Domain Changes

- Add `RetailerConnection`, `AutomationRun`, `AutomationRunItem`, `BrowserSession`, `AutomationStep`, `AutomationIntervention`, `CartSnapshot`, and structured cart-line records. Every record is team-scoped and policy-protected.
- Make a retailer connection team-owned but assign an `owner_user_id`; only that owner can authenticate, reauthenticate, or disconnect it. Store the Browserbase Context ID with Laravel’s encrypted cast and never store credentials, cookies, CDP URLs, or Live View URLs.
- Reference the exact existing `ShoppingListRevision` from each run and copy its included, non-pantry item state into the run. Later shopping-list edits do not mutate an active run; the UI offers cancel-and-rebuild when revisions diverge.
- Add explicit enums:
  - Connection: `pending_login`, `checking`, `connected`, `reauthentication_required`, `disconnected`, `revoked`, `error`.
  - Run: `checking_connection`, `awaiting_reauthentication`, `inspecting_existing_cart`, `awaiting_existing_cart_decision`, `queued`, `running`, `awaiting_item_decision`, `reconciling`, `ready_for_review`, `superseded`, `cancelled`, `failed`, `expired`.
  - Item: `pending`, `searching`, `matched`, `substituted`, `unavailable`, `skipped`, `awaiting_decision`, `failed`.
- Add Chef-owned contracts:
  - `BrowserSessionProvider` for context/session creation, Live View retrieval, closure, and deletion.
  - `ComputerUseClient` for the direct OpenAI Responses `computer_call` loop.
  - `ComputerExecutor` for a versioned JSON-lines protocol with the TypeScript worker.
  - `RetailerCartAdapter` for authentication checks, cart inspection, Woolworths-specific state interpretation, and reconciliation.
  - `ComputerUseEngine::advance(AutomationRun)` as the Laravel orchestration boundary.
- Implement `BrowserbaseBrowserSessionProvider` through the Browserbase REST API and `WoolworthsCartAdapter` as the first retailer adapter. Keep the contracts capable of supporting Coles and the Chrome-extension executor later without changing domain actions.
- Add an `automation` queue. A unique advancement job launches the TypeScript worker for a bounded chunk, persists every verified action, and exits on an intervention, terminal state, action/runtime limit, or safe checkpoint.
- Add an in-repo TypeScript automation package using `@browserbasehq/sdk` and `playwright-core`. The worker connects only to a Laravel-supplied Browserbase CDP URL, accepts typed JSON-lines commands, returns sanitized observations/screenshots, and never accesses Chef’s database or authorisation rules.
- Laravel owns the OpenAI Responses call, response continuation IDs, action validation, run limits, audit records, and state transitions. Screenshots remain in memory only long enough to produce the next response and are not persisted.

## Product Flow and Safety

- Add `Prepare Woolworths cart` to the Shopping workspace only when the list has items, is not stale, and has no unresolved recipes. The click is the explicit approval to mutate the retailer cart at that revision.
- If Woolworths is not connected, route to a dedicated connection surface. Create one Browserbase Context per retailer login, start a session with persistence enabled and recording disabled, navigate to Woolworths login, and expose a writable Live View only to the authenticated owner.
- Do not attach the model during login or MFA. After the user finishes, run a deterministic protected-page probe. Mark the connection connected only after the probe passes, close the session, allow context persistence to finish, and return to Shopping for an explicit cart-start click.
- Before every run, open a fresh session with the saved Context and repeat the authentication probe. Use consistent Australian region/proxy settings and prevent concurrent sessions from using the same Context.
- If authentication fails initially or mid-run, stop the agent before exposing Live View, preserve completed outcomes, set `awaiting_reauthentication`, and let the owner sign in. After verification, inspect the actual cart and resume only missing work.
- Inspect the cart before mutation. If it is non-empty, always pause and ask the user to:
  - merge Chef’s items with it;
  - explicitly replace it; or
  - cancel the run.
  Never remove pre-existing items without the replace choice. In merge mode, label pre-existing lines separately and exclude them from Chef item-match success while showing both Chef subtotal and whole-cart total.
- Execute one shopping requirement at a time. Prefer deterministic search, known controls, quantity setting, and verification; invoke computer-use reasoning only for unfamiliar page state, candidate ambiguity, substitutions, or recovery.
- Allow only screenshot, pointer, scroll, wait, safe keypress, and typing actions on allowlisted Woolworths origins. Block authentication fields, CAPTCHA handling, account settings, addresses, fulfilment, payment, checkout, downloads, uploads, clipboard access, and non-allowlisted navigation.
- After every add or quantity change, verify product identity, quantity, cart count, price policy, duplicates, and visible cart persistence. A click without verified cart state is not success.
- Pause for substitutions outside explicit policy, price-limit breaches, ambiguous dietary suitability, bot detection, unexpected sensitive screens, or low-confidence matches. Page content is untrusted and cannot broaden permissions.
- Reconcile the full remote cart into an immutable `CartSnapshot`. Group the review into matched, substituted, unavailable, quantity-adjusted, price-changed, pre-existing, and unresolved lines.
- Present `Open Woolworths cart` only after reconciliation. It opens the normal Woolworths cart surface; the slice is not complete until a live authenticated trial proves that the Browserbase-built cart appears in the same account’s ordinary Woolworths app/site. If account-level cart sync fails, retain the adapter architecture but do not substitute Live View checkout or mark M6 evidence satisfied.
- Disconnecting deletes the Browserbase Context, revokes outstanding Live Views, cancels active runs, and clears the encrypted provider reference.
- Disable Browserbase recording for every authenticated session. Do not persist screenshots, Live View URLs, CDP URLs, credentials, MFA data, addresses, account history, or payment information. Retain only redacted domain audit events and verified cart observations.

## Delivery Sequence

1. **Foundation:** Add configuration, feature flags, migrations, models, enums, policies, factories, domain contracts, fake implementations, and documentation updates resolving Browserbase-first execution versus the current extension-first wording.
2. **Connection:** Implement Browserbase Context/session lifecycle, recording-disabled Live View, deterministic Woolworths authentication verification, disconnection, ownership rules, and reauthentication.
3. **Run creation:** Freeze a shopping revision, validate readiness, acquire a per-connection lease, inspect existing cart state, and capture the user’s merge/replace/cancel decision.
4. **Automation:** Add the TypeScript CDP worker, direct Responses client, action policy, bounded queued loop, item outcomes, retries, pause/cancel/expiry, and crash-safe reconciliation.
5. **Review and handoff:** Add progress polling, calm activity status, intervention UI, cart exception review, final snapshot, normal-Woolworths handoff, and recovery when the cart is missing or changed.
6. **Proof and documentation:** Run a gated authenticated Woolworths trial, prove cross-session authentication, reauthentication, non-empty-cart handling, cart persistence, normal-app visibility, recording-disabled operation, and human checkout boundary. Update `README.md`, `docs/IMPLEMENTATION.md`, and `docs/MILESTONES.md`; do not mark M6 complete until its full retailer evidence is satisfied.

## Test Plan

- Feature tests for connection ownership, encrypted provider references, disconnect/revoke, state transitions, idempotency, per-connection locking, and cross-team isolation.
- Action tests proving run creation rejects stale, unresolved, empty, unauthorised, or mismatched revisions and preserves its frozen snapshot after later list edits.
- Fake-provider tests for connected, expired, revoked, MFA, timeout, bot-detection, session-loss, and reauthentication-resume paths.
- Policy tests rejecting non-Woolworths origins, sensitive fields/pages, forbidden actions, price breaches, unapproved substitutions, and concurrent human/agent control.
- Recorded worker fixtures for authentication probes, search results, successful adds, false-positive clicks, duplicates, quantity changes, modal recovery, empty/non-empty carts, and final reconciliation.
- Recovery tests proving queue retries and process crashes do not duplicate cart lines and always reconcile external state before continuing.
- Browser journeys at desktop and 390 × 844 for just-in-time connection, simulated Live View, authentication failure, reauthentication, existing-cart decision, progress, intervention, cancellation, cart review, and Woolworths handoff.
- A gated live test using an authorised Woolworths account verifies Context persistence, recording-disabled sessions, authenticated five-plus-item cart creation, reauthentication, account-level cart sync to the normal app/site, and no checkout interaction.
- Run migration rollback/reapply, focused Pest and browser suites, Composer test and coverage gates, PHPStan, Pint check mode, TypeScript, ESLint, Prettier, production build, dependency audits, and React Doctor.

## Assumptions and Defaults

- The first slice supports Woolworths only; Coles and the Chrome extension remain later adapters.
- Supermarket connection is offered just in time, not during onboarding.
- Existing carts always require an explicit merge, replace, or cancel decision.
- Checkout must occur through the user’s normal Woolworths experience; Live View checkout is not an accepted fallback.
- Browserbase and OpenAI are runtime providers, not Chef’s source of truth.
- Normal tests never call Browserbase, OpenAI, Woolworths, or mutate a live cart.
- Feature flags keep connection and cart mutation disabled until configuration and live evidence are present.
- Retailer terms, privacy obligations, authenticated automation tolerance, operating cost, and normal-app cart synchronisation remain release gates.
- Existing unrelated dirty-worktree changes are preserved.
