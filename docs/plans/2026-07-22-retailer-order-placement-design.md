# Retailer order placement rewrite

Status: implementation landed (domain, shopping UI, Stagehand worker) —
live Woolworths pilot still open. Implementation plan:
[`2026-07-22-retailer-order-placement-implementation.md`](2026-07-22-retailer-order-placement-implementation.md).

This document records the decision to move Chef's retailer finish line from
**prepare cart + human checkout in Woolworths** to **select fulfilment in Chef
+ confirm + Chef places the order with the account's default card on file**.

It supersedes the handoff language in M6 / M6.1 for future work. Historical M6
cart-preparation evidence remains valid as the foundation for authenticated
Browserbase sessions and cart mutation; the product end state changes.

## Product boundary

### What Chef may do after household confirmation

1. Prepare and verify a Woolworths cart from an approved, frozen shopping
   revision and exact product plan.
2. Read available delivery or pickup options (days and time slots) from the
   authenticated retailer session.
3. Present those options in Chef's structured UI.
4. After the household selects fulfilment type, day, and time slot and
   confirms in Chef, apply that selection in the retailer session and submit
   the order using the **default payment method already on file** in the
   Woolworths account.
5. Record the resulting order confirmation into Chef's durable `Order` model.

### What Chef must not do

- Store, scrape, or transmit card numbers, CVVs, or full payment payloads.
- Choose among on-file cards (v1 uses the retailer's default only).
- Change delivery address, store, or account settings without a separate
  explicit approval.
- Bypass the in-Chef confirmation step.
- Accept legal terms, marketing opt-ins, or account changes as part of submit.
- Infer allergies or safety constraints.

### Confirmation contract

The household confirmation in Chef is the direct human confirmation boundary
required before money moves. It must name:

- fulfilment type (delivery or pickup);
- selected day and time slot;
- that Chef will submit the Woolworths order using the default card on file;
- that the person can cancel before confirm.

After confirm, the agent may press the retailer's order/submit control. That is
deliberately autonomous relative to the retailer UI, and constrained relative
to Chef.

## Household journey (revised stage 3)

1. **Riff** — one coherent meal-plan proposal.
2. **Approve and prepare** — one action confirms the plan and authorises
   cart preparation within the approval fingerprint.
3. **Choose fulfilment and place** — resolve genuine exceptions, choose
   delivery or pickup, pick an available day/time in Chef, confirm, and let
   Chef submit the order.
4. **Cook** — from the reconciled plan and placed-order expectation.

Internal states (auth, cart merge, item retries, slot scrape, submit) stay
internal. The household sees momentum, not an operations console.

## Technical direction (Approach 2)

Rewrite the retailer automation module around one durable Laravel run and a
thin Browserbase + Stagehand/Playwright worker.

- Prefer deterministic retailer tools over vision computer-use.
- Use Stagehand `act` / `observe` / `extract` (with caching) for resilient DOM
  actions when hardcoded Playwright controls miss.
- Keep OpenAI native computer-use out of the happy path; delete it once
  Stagehand recovery is proven, or retain only as a last-resort flag.
- Keep Browserbase Context + recording-disabled Live View for owner login and
  reauthentication.
- Keep Laravel as policy and system of record.

### Domain model

Primary aggregate: **`RetailerOrderRun`** (name TBD). This **replaces**
`AutomationRun` rather than wrapping it. Pre-launch code that only exists for
the old cart-handoff / computer-use loop should be deleted in the same rewrite.

Suggested fields / relations:

- `team_id`, `shopping_list_id`, `shopping_list_revision`, `started_by_user_id`
- `retailer_connection_id`
- frozen `product_plan` / item outcomes (reuse `CartProductPlan` ideas)
- `fulfilment_type`: `delivery` | `pickup` | null
- `fulfilment_options_snapshot` (scraped slots; expires)
- `selected_slot` (id, label, start/end, fee if known)
- `confirmation` (user_id, confirmed_at, fingerprint of cart + slot)
- `retailer_order_reference` / confirmation text
- `status` (see state machine)
- redacted audit steps

Related:

- `RetailerConnection` — keep Context ownership and owner-only auth
- `CartSnapshot` — keep as cart evidence before submit
- `Order` / `OrderLine` — created or finalised from successful submit, not only
  from post-hoc human total entry

### State machine

```text
draft
  → preparing_cart
  → awaiting_cart_decision          # non-empty cart: merge | replace | cancel
  → awaiting_item_decision          # ambiguity / price / substitution
  → awaiting_reauthentication
  → cart_ready
  → fetching_fulfilment_options
  → awaiting_fulfilment_selection  # household picks type + slot in Chef
  → awaiting_order_confirmation     # explicit confirm naming default card
  → submitting_order
  → placed
  → awaiting_placement_verification  # likely submitted; confirmation unparsed
  → failed | cancelled
```

Rules:

- Only `awaiting_*` states require household input.
- `submitting_order` is irreversible from the household's point of view once
  the retailer accepts; retries must reconcile remote state first.
- Slot snapshots expire; stale selection returns to
  `fetching_fulfilment_options`.
- Confirmation fingerprint must cover cart checksum + selected slot +
  fulfilment type; any cart change after confirm invalidates confirm.
- If submit likely succeeded but the confirmation reference cannot be parsed,
  transition to `awaiting_placement_verification` (not `placed`), block
  automatic re-submit, show a Woolworths link, allow manual order-number entry
  or explicit “yes, it placed”, then promote to `placed`.

### Laravel shape

Idiomatic surfaces:

- Actions: `StartRetailerOrderRun`, `AdvanceRetailerOrderRun`,
  `ResolveCartDecision`, `SelectFulfilmentSlot`, `ConfirmRetailerOrder`,
  `CancelRetailerOrderRun`, `RecordPlacedRetailerOrder`,
  `VerifyRetailerPlacement`
- Job: `AdvanceRetailerOrderRunJob` on the `automation` queue (chunked)
- Policy: team-scoped; fulfilment selection and order confirmation may be
  performed by any authorised household member who can shop. Connection owner
  remains required for login, reauthentication, disconnect, and Live View
  takeover.
- Worker: greenfield Node process per session (Stagehand + Playwright),
  commanded by typed tools such as:
  - `probe_auth`
  - `inspect_cart`
  - `clear_cart`
  - `add_product`
  - `verify_cart_line`
  - `extract_fulfilment_options`
  - `apply_fulfilment_slot`
  - `submit_order_with_default_payment`
  - `extract_order_confirmation`

No vision loop in the default path. The LLM, if used at all during a run, calls
these tools; it does not click raw coordinates.

### Safety retained (simplified)

Keep:

- frozen shopping revision
- owner-only password/MFA Live View
- recording disabled for auth/submit sessions
- verify-after-mutate for cart lines
- pause on bot detection / sensitive screens
- no persistence of CDP URLs, screenshots, credentials, or payment payloads
- redacted audit trail of confirm → submit → confirmation reference

Simplify away where possible:

- OpenAI computer-use continuation protocol
- persistent fenced actor complexity beyond what Browserbase session safety
  requires
- dual action-budget accounting tied to CUA turns

## Documentation impact

Update in the same change set as this design lands in product truth:

- `README.md` — Shop stage, four-stage journey, approval boundaries, MVP,
  delivery sequence, stack wording
- `AGENTS.md` — product invariant and safety boundary
- `docs/IMPLEMENTATION.md` — momentum boundary; computer-use section becomes
  retailer-order execution boundary
- `docs/MILESTONES.md` — new target after cart prep; do not erase M6 evidence
- `docs/STYLE.md` — handoff copy and microcopy
- `docs/COMMERCIAL-STRATEGY.md` — claims that “checkout stays yours” where they
  conflict (follow-up pass)

## Resolved decisions

1. **Confirm authorisation:** any authorised household member who can shop may
   select fulfilment and confirm order placement. Connection-owner-only remains
   required for retailer login, reauthentication, disconnect, and Live View
   takeover — not for Confirm.
2. **Pickup path:** same as delivery — scrape available windows, pick type /
   day / time in Chef, confirm, then the agent applies the slot and submits.
3. **Run model:** replace. Introduce a clean `RetailerOrderRun` (name may be
   refined) as the sole retailer orchestration aggregate. Remove
   `AutomationRun` and the computer-use-centric machinery that only served the
   old cart-handoff path, rather than wrapping it.
4. **Browser worker:** greenfield. New Stagehand/Playwright tool worker;
   delete the fenced actor / OpenAI computer-use execute path rather than
   adapting it in place.
5. **Unparsed confirmation after likely submit:** needs review — do not claim
   placed. Use a distinct uncertain-success state (e.g.
   `awaiting_placement_verification`), block automatic re-submit, show a
   Woolworths link, and promote to `placed` only after the household confirms
   or enters the order reference.

## Open decisions for the implementation plan

None remaining. Implementation plan approved for execution.

## Non-goals for the first rewrite slice

- Coles
- Chrome extension
- Multi-card selection
- Address or store changes
- Autonomous submit without in-Chef confirm
- Perfect recovery of every Woolworths UI edge case without pause
