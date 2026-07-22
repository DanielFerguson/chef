# Retailer Order Placement Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the M6 cart-handoff / computer-use automation stack with a single Laravel `RetailerOrderRun` that prepares a Woolworths cart, scrapes delivery/pickup slots into Chef, and after in-app confirmation submits the order with the account’s default card on file.

**Architecture:** Laravel owns the durable run, policies, confirmation fingerprint, and audit trail. A greenfield TypeScript Stagehand/Playwright worker executes narrow retailer tools over a Browserbase CDP session. OpenAI vision computer-use and the fenced actor stack are deleted. Connection-owner Live View remains for login/reauth/takeover only.

**Tech Stack:** Laravel 13 actions/jobs/policies, Pest, Inertia/React, Browserbase, Playwright, Stagehand, existing `RetailerConnection` / `CartProductPlan` / `Order` models.

**Design:** [`2026-07-22-retailer-order-placement-design.md`](2026-07-22-retailer-order-placement-design.md)

---

## Working agreements

- Pre-launch rewrite: prefer delete over wrap.
- TDD for domain actions and policies; fake the browser worker in PHP tests.
- Never call live Browserbase, OpenAI, or Woolworths in the normal suite.
- Commit after each task (or logical pair of test+impl) with a focused message.
- Keep feature flags off by default until a live pilot gate exists.
- Do not expand to Coles, multi-card selection, or address changes in this plan.

## Keep vs delete

**Keep (adapt as needed):**

- `RetailerConnection`, owner lease, Browserbase Context
- `BrowserSession` + `BrowserbaseBrowserSessionProvider`
- Live View login / reauth / takeover actions
- `CartProductPlan` + `WoolworthsCatalogueDiscovery`
- `CartSnapshot` / lines (pre-submit evidence)
- `Order` / `OrderLine`
- `ApproveMealPlanForShopping` / shopping approval fingerprint (wire to new start action)

**Delete (once replacements pass):**

- `AutomationRun`, `AutomationRunItem`, `AutomationStep`, `AutomationIntervention` models + factories + policies + controllers
- `LaravelComputerUseEngine`, `ComputerUseClient`, `OpenAIComputerUseClient`, CUA fakes/fixtures
- `web/automation/actor.ts`, `launch-actor.ts`, fencing / Unix-socket actor protocol
- Cart-handoff-only UI (“open Woolworths and checkout yourself” as primary CTA)
- Tests that only assert CUA / handoff behaviour (rewrite against new run)

---

### Task 1: Mark design approved and add status enums scaffold

**Files:**
- Modify: `docs/plans/2026-07-22-retailer-order-placement-design.md`
- Create: `web/app/Enums/RetailerOrderRunStatus.php`
- Create: `web/tests/Unit/Enums/RetailerOrderRunStatusTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Enums\RetailerOrderRunStatus;

it('exposes household-input awaiting statuses', function () {
    expect(RetailerOrderRunStatus::AwaitingFulfilmentSelection->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::AwaitingOrderConfirmation->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::AwaitingPlacementVerification->requiresHouseholdInput())->toBeTrue()
        ->and(RetailerOrderRunStatus::SubmittingOrder->requiresHouseholdInput())->toBeFalse()
        ->and(RetailerOrderRunStatus::Placed->isTerminal())->toBeTrue();
});
```

**Step 2: Run test to verify it fails**

Run: `cd web && ./vendor/bin/pest tests/Unit/Enums/RetailerOrderRunStatusTest.php`

Expected: FAIL (enum missing)

**Step 3: Implement the enum**

Statuses: `Draft`, `PreparingCart`, `AwaitingCartDecision`, `AwaitingItemDecision`, `AwaitingReauthentication`, `CartReady`, `FetchingFulfilmentOptions`, `AwaitingFulfilmentSelection`, `AwaitingOrderConfirmation`, `SubmittingOrder`, `Placed`, `AwaitingPlacementVerification`, `Failed`, `Cancelled`.

Add helpers: `requiresHouseholdInput()`, `isTerminal()`, `blocksResubmit()` (true for `SubmittingOrder`, `Placed`, `AwaitingPlacementVerification`).

**Step 4: Re-run test — PASS**

**Step 5: Update design status to approved; commit**

```bash
git add docs/plans/2026-07-22-retailer-order-placement-design.md \
  web/app/Enums/RetailerOrderRunStatus.php \
  web/tests/Unit/Enums/RetailerOrderRunStatusTest.php
git commit -m "$(cat <<'EOF'
Add RetailerOrderRunStatus and approve order-placement design.

EOF
)"
```

---

### Task 2: Migration and `RetailerOrderRun` model

**Files:**
- Create: `web/database/migrations/2026_07_22_100000_create_retailer_order_runs_table.php`
- Create: `web/app/Models/RetailerOrderRun.php`
- Create: `web/database/factories/RetailerOrderRunFactory.php`
- Create: `web/tests/Feature/RetailerOrderRunModelTest.php`

**Step 1: Write failing model/factory test**

Assert team-scoped creation with: `shopping_list_id`, `shopping_list_revision_id`, `retailer_connection_id`, `started_by_user_id`, `cart_product_plan_id`, `status`, nullable fulfilment/selection/confirmation JSON columns, `cart_checksum`, `confirmation_fingerprint`, `retailer_order_reference`, `expires_at`.

**Step 2: Run — FAIL**

**Step 3: Migration + model + factory**

Suggested columns:

- FKs as above
- `status` string
- `fulfilment_type` nullable string
- `fulfilment_options` JSON nullable + `fulfilment_options_expires_at`
- `selected_slot` JSON nullable
- `confirmation` JSON nullable (`user_id`, `confirmed_at`, `fingerprint`)
- `cart_checksum` nullable string
- `retailer_order_reference` nullable string
- `failure_message` nullable text
- `limits` JSON nullable
- timestamps

**Step 4: Test PASS; commit**

```bash
git commit -m "$(cat <<'EOF'
Add RetailerOrderRun model and migration.

EOF
)"
```

---

### Task 3: Run items and audit steps

**Files:**
- Create: `web/app/Models/RetailerOrderRunItem.php`
- Create: `web/app/Models/RetailerOrderStep.php`
- Create: `web/app/Enums/RetailerOrderRunItemStatus.php`
- Migrations + factories + focused model tests

**Step 1: Failing tests for item outcomes** (`pending`, `searching`, `matched`, `substituted`, `unavailable`, `skipped`, `failed`) and append-only redacted steps.

**Step 2: Implement minimal models/migrations.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Add retailer order run items and audit steps.

EOF
)"
```

---

### Task 4: Policy — shoppers confirm; owners authenticate

**Files:**
- Create: `web/app/Policies/RetailerOrderRunPolicy.php`
- Create: `web/tests/Feature/RetailerOrderRunPolicyTest.php`
- Modify: `web/app/Providers/AppServiceProvider.php` (register policy)

**Step 1: Failing policy tests**

- Team member who can shop: `view`, `selectFulfilment`, `confirm`, `verifyPlacement`, `cancel`
- Non-member: deny all
- Non-owner team member: deny `takeOver`, `reauthenticate` (those stay on `RetailerConnectionPolicy`)
- Connection owner: existing connection auth tests still pass

**Step 2: Implement policy mirroring shopping-list shopper ability.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Authorise shoppers to confirm retailer orders.

EOF
)"
```

---

### Task 5: `RetailerBrowser` contract and fake (no CUA)

**Files:**
- Create: `web/app/Retailer/Contracts/RetailerBrowser.php`
- Create: `web/app/Retailer/Data/*.php` (small DTOs: `CartInspection`, `AuthCheck`, `FulfilmentOptions`, `SlotSelection`, `SubmitResult`, `ToolResult`)
- Create: `web/app/Retailer/Testing/FakeRetailerBrowser.php`
- Create: `web/tests/Feature/FakeRetailerBrowserTest.php`
- Modify: `web/app/Providers/AppServiceProvider.php`

**Step 1: Failing test** exercising fake scripted responses for:

```php
$browser->probeAuth($session);
$browser->inspectCart($session);
$browser->clearCart($session);
$browser->addProduct($session, $product);
$browser->extractFulfilmentOptions($session, 'delivery');
$browser->applyFulfilmentSlot($session, $slot);
$browser->submitOrderWithDefaultPayment($session);
$browser->extractOrderConfirmation($session);
```

**Step 2: Define interface + fake + bind fake in `testing`.**

Do **not** create OpenAI computer-use types.

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Add RetailerBrowser contract and test fake.

EOF
)"
```

---

### Task 6: `StartRetailerOrderRun` action

**Files:**
- Create: `web/app/Actions/Retailer/StartRetailerOrderRun.php`
- Create: `web/tests/Feature/StartRetailerOrderRunTest.php`
- Modify: `web/app/Actions/Automation/ContinueApprovedShopping.php` (or successor) to call the new action

**Step 1: Failing tests**

- Requires current shopping approval fingerprint, ready `CartProductPlan`, connected retailer, mutation flag enabled
- Freezes revision + plan into run items
- Dispatches `AdvanceRetailerOrderRunJob`
- Idempotent if an active run already exists for the same revision/plan

**Step 2: Implement action; keep behind existing Woolworths feature flags.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Start RetailerOrderRun from approved shopping plan.

EOF
)"
```

---

### Task 7: `AdvanceRetailerOrderRun` — cart preparation path

**Files:**
- Create: `web/app/Actions/Retailer/AdvanceRetailerOrderRun.php`
- Create: `web/app/Jobs/AdvanceRetailerOrderRunJob.php`
- Create: `web/tests/Feature/AdvanceRetailerOrderRunCartTest.php`

**Step 1: Failing tests with `FakeRetailerBrowser`**

- Auth fail → `AwaitingReauthentication`
- Non-empty cart → `AwaitingCartDecision`
- Empty cart → add products via fake → items matched → `CartReady` then auto-enter `FetchingFulfilmentOptions` when no household pause needed
- Bot/sensitive → pause safely
- Chunked job: unique per run, `WithoutOverlapping`, re-dispatch while `shouldContinue`

**Step 2: Implement advance for cart states only** (stop before fulfilment scrape if easier; scrape in Task 8). Prefer verify-after-add using fake cart lines.

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Advance retailer order runs through cart preparation.

EOF
)"
```

---

### Task 8: Fulfilment options scrape + selection actions

**Files:**
- Create: `web/app/Actions/Retailer/SelectFulfilmentSlot.php`
- Extend: `AdvanceRetailerOrderRun` for `FetchingFulfilmentOptions`
- Create: `web/tests/Feature/SelectFulfilmentSlotTest.php`

**Step 1: Failing tests**

- Advance scrapes options into `fulfilment_options` with expiry
- Status → `AwaitingFulfilmentSelection`
- Shopper selects delivery/pickup + slot id present in snapshot
- Expired snapshot rejects selection and re-queues fetch
- Cross-team denied

**Step 2: Implement; on success → `AwaitingOrderConfirmation`.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Scrape and select retailer fulfilment slots in Chef.

EOF
)"
```

---

### Task 9: Confirm + submit + placement verification

**Files:**
- Create: `web/app/Actions/Retailer/ConfirmRetailerOrder.php`
- Create: `web/app/Actions/Retailer/VerifyRetailerPlacement.php`
- Create: `web/app/Actions/Retailer/RecordPlacedRetailerOrder.php`
- Create: `web/tests/Feature/ConfirmRetailerOrderTest.php`

**Step 1: Failing tests**

- Confirm requires shopper auth, names fingerprint of cart checksum + fulfilment type + selected slot
- Confirm → `SubmittingOrder` + dispatch advance
- Fake submit success + parsed reference → `Placed` + `Order` / lines created
- Fake submit success + unparsed reference → `AwaitingPlacementVerification`, **no** second submit on advance
- `VerifyRetailerPlacement` with manual reference or explicit acknowledgement → `Placed`
- Confirm copy payload includes default-card-on-file consequence for UI

**Step 2: Implement; never store card details.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Confirm and submit retailer orders with verification pause.

EOF
)"
```

---

### Task 10: Cart decision / cancel / reauth resume wiring

**Files:**
- Create: `web/app/Actions/Retailer/ResolveCartDecision.php`
- Create: `web/app/Actions/Retailer/CancelRetailerOrderRun.php`
- Modify: `web/app/Actions/Automation/VerifyRetailerConnection.php` (resume new run type)
- Tests: `ResolveCartDecisionTest`, `CancelRetailerOrderRunTest`

**Step 1: Failing tests for merge/replace/cancel; cancel terminal; reauth success resumes `PreparingCart`.**

**Step 2: Implement.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Wire cart decisions, cancel, and reauth resume for order runs.

EOF
)"
```

---

### Task 11: HTTP + Inertia payloads

**Files:**
- Create: `web/app/Http/Controllers/RetailerOrderRunController.php` (or split select/confirm/verify)
- Create: `web/app/Retailer/RetailerOrderRunView.php`
- Modify: `web/routes/web.php`
- Modify: `web/app/Http/Controllers/ShoppingListController.php` / dashboard shopping props
- Modify: `web/resources/js/features/shopping/types.ts`
- Tests: feature HTTP tests for select, confirm, verify, cancel

**Step 1: Failing HTTP tests** (Inertia/JSON as existing shopping routes do).

**Step 2: Implement thin controllers calling actions; expose run view with:**

- status, items, interventions-as-needed
- fulfilment options
- selected slot
- confirmation checklist copy
- placement verification CTA
- **no** primary “checkout in Woolworths yourself” when confirm/submit path is available

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Expose retailer order run HTTP and shopping payloads.

EOF
)"
```

---

### Task 12: Shopping UI — slot picker, confirm, verification

**Files:**
- Modify: `web/resources/js/pages/shopping/show.tsx`
- Create as needed: `web/resources/js/features/shopping/fulfilment-slot-picker.tsx`
- Create: `web/resources/js/features/shopping/confirm-order-panel.tsx`
- Create: `web/resources/js/features/shopping/placement-verification-panel.tsx`
- Browser: extend `web/tests/Browser/ShoppingListTest.php`

**Step 1: Write/adjust browser coverage** for:

- options list when `AwaitingFulfilmentSelection`
- confirm panel names delivery/pickup, slot, default card on file
- verification panel when unparsed confirmation

**Step 2: Implement UI following `docs/STYLE.md` (quiet, one job per panel).**

**Step 3: Run**

```bash
cd web && npm run types:check
cd web && ./vendor/bin/pest tests/Browser/ShoppingListTest.php
```

**Step 4: Commit**

```bash
git commit -m "$(cat <<'EOF'
Add fulfilment selection and order confirmation UI.

EOF
)"
```

---

### Task 13: Greenfield Stagehand worker skeleton

**Files:**
- Delete (later task, or now if unused): `web/automation/actor.ts`, `launch-actor.ts`
- Create: `web/automation/package boundary` — prefer `web/automation/src/main.ts`, `tools.ts`, `woolworths/*.ts`
- Modify: `web/package.json` scripts (`automation:build`)
- Add dependency: `@browserbasehq/stagehand` (pin current major)
- Create: `web/app/Retailer/Browserbase/StagehandRetailerBrowser.php` (PHP side invoking worker)
- Create: protocol fixture test like existing `worker-protocol.json` pattern

**Step 1: Define JSON-lines or argv protocol for one-shot/session tools** (keep simpler than fenced actor):

- Laravel supplies transient CDP URL via env
- Worker returns sanitised JSON only
- No OpenAI key in worker
- No DB access

**Step 2: Implement `probe_auth` + `inspect_cart` only first; fake PHP still used in most tests.**

**Step 3:** `npm run automation:check` PASS

**Step 4: Commit**

```bash
git commit -m "$(cat <<'EOF'
Add greenfield Stagehand retailer browser worker skeleton.

EOF
)"
```

---

### Task 14: Woolworths cart tools in worker

**Files:**
- `web/automation/src/woolworths/cart.ts` (or equivalent)
- Port useful selector logic from old `worker.ts` **without** `requires_computer_use` / execute_action
- Stagehand `act`/`extract` only as recovery when Playwright locators miss
- PHP adapter methods call worker tools
- Contract tests with recorded fixtures (no live site)

**Step 1: Fixture-based tests for add/inspect/clear tool payloads.**

**Step 2: Implement deterministic-first tools.**

**Step 3: PASS + commit**

```bash
git commit -m "$(cat <<'EOF'
Implement Woolworths cart tools without computer-use.

EOF
)"
```

---

### Task 15: Fulfilment + submit tools in worker

**Files:**
- `web/automation/src/woolworths/fulfilment.ts`
- `web/automation/src/woolworths/submit.ts`
- Policy allowlist in PHP: block address edits; allow fulfilment + final submit **only** when run status is `SubmittingOrder` and confirmation fingerprint matches

**Step 1: Failing PHP policy/action tests** proving submit tool cannot be invoked without confirm.

**Step 2: Implement extract/apply/submit/extract_confirmation with Stagehand assist.**

**Step 3: Fixture tests; commit**

```bash
git commit -m "$(cat <<'EOF'
Add fulfilment and default-card submit retailer tools.

EOF
)"
```

---

### Task 16: Delete old automation stack

**Files (remove once greps are clean):**

- `web/app/Automation/LaravelComputerUseEngine.php`
- `web/app/Automation/OpenAI/*`
- `web/app/Automation/Contracts/ComputerUse*.php`
- `web/app/Jobs/AdvanceAutomationRunJob.php`
- `web/app/Models/AutomationRun*.php`, interventions/steps if unused
- Controllers/routes/policies/factories/tests solely for old run
- `web/automation/actor.ts`, `launch-actor.ts`, old `worker.ts` if fully replaced
- Config keys for computer-use model / CUA budgets

**Step 1:** `rg "AutomationRun|ComputerUseEngine|OpenAIComputerUse" web` → only historical docs/comments allowed.

**Step 2:** Delete dead code; fix `AppServiceProvider` bindings; migrate or drop old tables in a new migration (`drop_automation_runs_tables`) — pre-launch OK to drop.

**Step 3:**

```bash
cd web && ./vendor/bin/pest --filter=Retailer
cd web && ./vendor/bin/pest tests/Feature/BrowserbaseCartPreparationTest.php # rewrite or delete
composer test # or documented narrow then full gate
```

**Step 4: Commit**

```bash
git commit -m "$(cat <<'EOF'
Remove AutomationRun and OpenAI computer-use stack.

EOF
)"
```

---

### Task 17: Documentation alignment pass

**Files:**
- `docs/IMPLEMENTATION.md` — replace computer-use boundary section with retailer-order execution boundary matching the new code
- `docs/MILESTONES.md` — mark M6.2 checklist progress as work lands
- `docs/COMMERCIAL-STRATEGY.md` — finish contradictory “checkout stays yours” passages (checklist item)
- `README.md` — status blurb once skeleton ships
- Update design doc status: implemented / in progress

**Step 1: Edit docs to match shipped behaviour (no aspirational lie).**

**Step 2: Commit**

```bash
git commit -m "$(cat <<'EOF'
Align implementation docs with retailer order placement.

EOF
)"
```

---

### Task 18: Full verification gate

**Step 1: Run**

```bash
cd web && npm run automation:check && npm run types:check && npm run lint:check
cd web && ./vendor/bin/pest --parallel
# or composer test if that is the repo full gate
```

**Step 2: Manually note live-pilot gaps still open** (Browserbase + real Woolworths submit) — do not mark M6.2 complete without live evidence.

**Step 3: Final commit if fixes needed; stop for human review.**

---

## Suggested execution order summary

| Phase | Tasks | Outcome |
|---|---|---|
| Domain core | 1–4 | Enums, models, policies |
| Orchestration | 5–10 | Fake browser + full run state machine |
| Product UI | 11–12 | Shopper can select/confirm/verify |
| Real worker | 13–15 | Stagehand Woolworths tools |
| Cleanup | 16–18 | Delete old stack, docs, gate |

## Out of scope (explicit)

- Coles adapter
- Chrome extension
- Card picker / card entry
- Address or store changes
- Autonomous submit without Chef confirm
- Live cost/latency evidence (separate pilot runbook update)

---

## Execution handoff

Plan complete and saved to `docs/plans/2026-07-22-retailer-order-placement-implementation.md`.

**Two execution options:**

1. **Subagent-Driven (this session)** — dispatch a fresh subagent per task, review between tasks  
2. **Parallel Session (separate)** — open a new session with executing-plans and run task batches with checkpoints  

Which approach?
