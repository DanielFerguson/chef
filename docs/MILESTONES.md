# Chef milestones to public version 1

This file tracks the path from product definition to the first public release. Update status and acceptance evidence as work lands; do not mark a milestone complete because its code exists if its end-to-end outcome is not usable.

## Status legend

- `[ ]` not started
- `[~]` in progress
- `[x]` complete
- `[!]` blocked, with the blocker explained directly beneath the item

## Version 1 release definition

Chef version 1 is the first publicly available release that lets a real family collaboratively:

1. onboard through a natural-language first-plan experience;
2. maintain inspectable household preferences and safety constraints;
3. plan meals over an arbitrary date range;
4. review recipes and cook from the plan;
5. generate and edit a budget-aware shopping list;
6. prepare a Woolworths or Coles cart through reviewed computer use;
7. use typed and voice conversation;
8. invite another account into the family team;
9. provide feedback that improves later recommendations.

Public release additionally requires secure tenancy, accessible core journeys, operational monitoring, documented privacy behaviour, recovery procedures, and a supportable deployment.

## M0 — Product and architecture definition

Status: `[x]`

- [x] Define the product thesis and four-stage experience.
- [x] Define conversational onboarding as the first meal plan.
- [x] Define the initial domain boundaries.
- [x] Choose Laravel, Inertia, React, TypeScript, shadcn/ui, and SQLite.
- [x] Define the OpenAI Responses, Realtime, computer-use, and MCP boundaries.
- [x] Choose the Laravel AI SDK for ordinary server-side agents.
- [x] Define team-as-family tenancy and distinguish `User` from `Person`.
- [x] Document the initial visual and interaction language.
- [x] Add durable contributor instructions.
- [x] Review and resolve contradictions across the product and implementation documents.
- [x] Commit the documentation baseline.

Exit evidence:

- README and supporting documents agree on terminology, version 1 scope, and architecture.
- Remaining unknowns are explicitly deferred rather than accidentally implied.

## M1 — Application foundation and team tenancy

Status: `[x]`

- [x] Scaffold Laravel 13 with the React/Inertia starter kit.
- [x] Configure TypeScript, Tailwind, shadcn/ui, Pest, formatting, and static analysis.
- [x] Establish the Chef application shell and responsive navigation.
- [x] Establish the Shared Table brand assets, typography, and design tokens.
- [x] Implement authentication.
- [x] Implement `Team`, `TeamMembership`, `TeamInvitation`, `Person`, and `UserPersonLink`.
- [x] Implement active-team selection and team-scoped route bindings.
- [x] Add policies and cross-team isolation tests.
- [x] Add seeded local accounts and a representative family fixture.
- [x] Establish CI for backend tests, frontend checks, and production builds.

Exit evidence:

- Two users can belong to one team and see the same empty Chef workspace.
- A user cannot read or mutate another team's records by changing identifiers.
- Desktop and narrow-screen shells pass a focused browser test.

Acceptance evidence recorded 16 July 2026:

- `composer test` passes 44 tests and 175 assertions, including registration, policies, active-family switching, scoped person bindings, and cross-team denial.
- ESLint, Prettier, TypeScript, PHPStan, Pint, and the production Vite build pass.
- React Doctor reports no errors; its remaining warnings are non-blocking starter-kit maintainability advice and an npm-inapplicable pnpm hardening check.
- The seeded Daniel and Tahlia accounts see the same family workspace, alongside a person without an account; a separate household fixture proves isolation.
- Browser checks pass at 1440 × 900 and 390 × 844, including the mobile navigation drawer, with no console errors.

## M2 — First-plan conversational onboarding

Status: `[x]`

- [x] Implement `MealPlan`, `MealSlot`, participant, conversation, message, preference, and constraint models.
- [x] Install and wrap the Laravel AI SDK behind `ChefConversationEngine`.
- [x] Implement the initial `ChefAgent` and read/write domain tools.
- [x] Stream typed assistant responses into the conversation.
- [x] Build conversational onboarding without a separate wizard.
- [x] Build the live plan and household inspector.
- [x] Support structured meal proposals and direct accept, reject, replace, and move actions.
- [x] Show an editable summary separating safety rules, stated preferences, defaults, and inferences.
- [x] Persist and resume the first plan and conversation.
- [x] Allow an invited second member to make an attributed plan change.
- [x] Add deterministic agent fakes and an end-to-end browser test.

### M2.1 — Adversarial hardening gate

- [x] Create non-account household people through an authorised conversational tool and retain the source message.
- [x] Link invitations to existing people without losing their household history.
- [x] Retain user-authored message provenance for every conversational safety constraint.
- [x] Make message turns, assistant replies, and retryable tool writes idempotent with database concurrency backstops.
- [x] Guard proposal decisions with locked, one-way state transitions.
- [x] Protect every M2 team-owned mutation with policies and active-team route binding.
- [x] Refactor the planning workspace into focused typed feature components that reset across plan navigation.
- [x] Cover retry, replay, failure, invitation, provenance, direct controls, navigation, and responsive behaviour.
- [x] Run production browser journeys in CI and enforce a measured coverage floor.

Exit evidence — first a-ha moment:

> A new user can describe their family and next few days in natural language, receive a relevant plan, adjust it conversationally or visually, invite another person, and return later to the same durable workspace.

Acceptance evidence recorded 16 July 2026:

- The complete Composer gate passes 61 tests and 237 assertions, including family-scoped route binding, durable conversation resumption, invitation acceptance, attributed second-member changes, and proposal accept, reject, replace, and move actions.
- A deterministic Laravel AI SDK tool-call loop turns a typed request into a message-linked meal proposal without a live OpenAI request; the proposal remains reviewable until a person accepts it.
- The planning workspace streams typed responses, exposes dated slots and selected meals, and keeps safety rules, stated preferences, working defaults, and labelled inferences separately editable.
- Browser tests pass the first-plan tool-and-accept journey at desktop size and the durable workspace at 390 × 844, with no JavaScript errors.
- ESLint, Prettier, TypeScript, PHPStan, Pint, and the production Vite build pass. React Doctor reports 100/100 with no issues.

M2.1 acceptance evidence recorded 16 July 2026:

- Conversational people, preferences, constraints, slots, and proposals retain their user-message source and are safe to replay; safety tools cannot assert their own confirmation.
- Completed turns replay one durable assistant response, active turns reject duplicate claims, and failed turns retry without duplicating structured side effects.
- Policy and route-binding tests cover every M2 mutation across family boundaries; proposal accept and reject transitions cannot be replayed or reversed.
- Five browser journeys cover the first-plan a-ha moment, plan navigation without state leakage, person-linked invitation context, visible safety provenance, direct proposal controls, and a 390 × 844 layout.
- CI runs backend coverage, frontend checks and production build, plus the real browser suite as independent required jobs.
- The final M2.1 gate passes 88 tests and 344 assertions at 88.7% application coverage; every team-owned policy is covered, React Doctor reports 100/100 with no findings, and both dependency audits report zero known vulnerabilities.

## M3 — Recipes and complete planning workspace

Status: `[x]`

- [x] Implement recipes, versions, ingredients, steps, equipment, and preparation notices.
- [x] Support recipe creation and a minimal import workflow.
- [x] Support arbitrary date spans and breakfast, lunch, dinner, snack, and custom slots.
- [x] Support leftovers, eating out, takeaway, skipped meals, and open slots.
- [x] Build plan calendar and list views with drag-and-drop scheduling.
- [x] Add participant and serving overrides per slot.
- [x] Add plan milestones, revision tracking, and stale-derived-data detection.
- [x] Explain recommendations using current constraints, recency, cost, and effort.

Exit evidence:

- A family can create and revise a complete one- or two-week plan without losing the recipe version or participant context attached to each slot.

M3 acceptance evidence recorded 16 July 2026:

- Recipes retain immutable versions with ingredient snapshots, ordered steps, equipment, preparation notices, servings, timings, and provenance; planned meals use a restrictive foreign key to the exact version selected.
- Recipe creation, deterministic text import, recipe inspection, and replay-safe AI recipe creation all invoke the same authorised Laravel actions. Repeated tool calls do not duplicate recipes or plan revisions.
- Conversation, calendar, and list views operate on the same structured plan. The calendar exposes drag scheduling, and every move also has a keyboard- and screen-reader-accessible select control.
- A focused exit-evidence test creates and confirms 28 lunches and dinners across fourteen days, then revises the plan without changing any stored recipe version or per-person serving context.
- Adversarial tests cover cross-family route binding and policies for every M3 tenant-owned root record, stale-write rollback, non-recipe relationship cleanup, version deletion protection, milestone staleness, and arbitrary meal states.
- Five M3 browser journeys cover recipe import and reading, recipe selection and confirmation, participant serving overrides, rescheduling with version retention, and the complete planning workspace at 390 × 844. Together with the earlier journeys, they pass without JavaScript errors.
- The final gate passes 104 backend tests and 454 assertions at 89.2% application coverage. Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, migration fresh/rollback/reapply, and both dependency audits pass; React Doctor reports 100/100 with no findings.

### M3.1 — Conversation reliability and testing instrumentation

Status: `[x]`

- [x] Add private, attributed thumbs-up/down feedback to assistant messages with optional reason tags and context.
- [x] Add a lightweight feedback checkpoint after planning confirmation without conflating it with person-specific meal feedback.
- [x] Retain the conversation, message, plan revision, milestone, and agent invocation that produced each response under review.
- [x] Give stated preferences exact human evidence and reject unsupported people, subjects, or unrelated later messages.
- [x] Correct misattributed preferences by superseding the wrong record while retaining the original assertion and correction.
- [x] Recover visible acknowledgements from successful tool-only turns and never persist a completed blank assistant response.
- [x] Derive plan readiness and the next action from structured state rather than conversation prose.
- [x] Require explicit confirmation after the final slot and carry confirmed plans toward shopping.
- [x] Replay the meal-plan-one failures as deterministic regression coverage without live model calls.
- [x] Pass the complete backend, frontend, browser, migration, audit, and React Doctor gate.

Exit evidence:

- A tester can flag the exact Chef response that failed, add optional context, and later connect it to the structured plan and agent invocation.
- A preference cannot be assigned to an unsupported person or cite unrelated evidence; a correction leaves one active truth with a human-verifiable history.
- A successful tool-only turn always produces a useful visible acknowledgement, and completing the last meal leads directly to review and explicit confirmation.

M3.1 acceptance evidence recorded 16 July 2026:

- Message feedback and the planning-confirmed checkpoint retain their author, conversation, message when applicable, plan revision, milestone, reason tags, optional context, and agent invocation. The household UI returns only the signed-in member's own feedback.
- Exact transcript regressions reject an unsupported `Dinner guest` attribution, persist Tahlia's pronoun-scoped pesto preference, reject `and 2 and 3` as food evidence, and correct fish and sausages through the real agent tools with one active owner and retained human sources.
- A tool-only replay of `and 2 and 3` selects both meals, synthesises a non-empty acknowledgement from the resulting plan revisions, reports all slots filled, and leads directly to review and explicit confirmation. An empty engine completion without a verifiable mutation fails instead of persisting a blank assistant message.
- The complete Composer gate passes 123 backend tests and 593 assertions at 88.6% application coverage. All 18 browser journeys pass with 124 assertions, including feedback, confirmation, plan management, household truth, markdown, messaging, planning, and responsive behaviour.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, SQLite fresh/rollback/reapply, npm audit, and Composer audit pass. React Doctor reports 100/100 with no findings.

## M4 — Shopping lists and budgets

Status: `[x]`

- [x] Aggregate recipe requirements and normalise units.
- [x] Implement structured `ShoppingListItem` records and list revisions.
- [x] Support manual staples, pantry exclusions, include/exclude state, and annotations.
- [x] Build a fast document-like shopping-list editor backed by structured rows.
- [x] Implement retailers, retail products, product preferences, and product matches.
- [x] Implement plan and household budget defaults and overrides.
- [x] Store estimated and actual order totals as historical snapshots.
- [x] Mark shopping lists stale and show diffs when a confirmed plan changes.

Exit evidence:

- A family can generate, edit, share, and complete a trustworthy list whose quantities remain traceable to meals or manual requests.

First vertical slice:

- Generate one team-scoped structured list from a confirmed plan and its exact recipe versions.
- Scale and aggregate compatible recipe quantities while retaining source-meal traceability.
- Open the Shopping workspace directly from the confirmed-plan handoff.
- Support adding staples, inline quantity and note edits, pantry and include/exclude state, completion, and list regeneration.
- Mark the list stale after a confirmed plan changes and explain the change before regeneration.
- Prove cross-family isolation and the complete Plan-to-Shop journey in a browser without live AI or retailer calls.

M4 completion evidence recorded 16 July 2026:

- Confirmed plans generate one idempotent family-scoped list. Compatible quantities are scaled to planned servings, kilograms and litres are normalised to base units, and every generated row retains its recipe ingredient and planned-meal sources.
- List revisions snapshot recipe, custom-meal, manual, staple, price, and product-match state after every meaningful mutation. Independent check-offs merge safely while conflicting document edits retain optimistic revision guards.
- Custom meals remain unresolved rather than guessed until a household member records explicit ingredients. Those rows retain their planned-meal source, survive safe regeneration, and block completion while unresolved.
- Coles and Woolworths catalogue records, retailer product packs, remembered brand/pack/substitution preferences, and per-item product matches remain separate from culinary ingredients. Saved preferences are returned to later matching surfaces.
- Household default budgets and per-plan overrides produce an effective budget. Projected totals explicitly report unmatched items, and immutable order and order-line snapshots retain estimated and actual totals at the completed list revision.
- Confirmed plan changes accumulate a structured stale diff from the source revision to the current revision. Stale lists are read-only and cannot be completed or matched until the household reviews and regenerates them.
- Every M4 team-owned model has policy coverage and current-team route binding where applicable. A second family member can update the shared list, while action and HTTP regressions deny cross-family budget, match, order, list, item, and resolution access.
- Nine focused M4 feature tests pass with 147 assertions. Three Shopping browser journeys pass with 20 assertions across the complete Plan-to-Shop-to-order path, explicit custom-meal resolution, and a 390 × 844 viewport. The post-audit complete suite passes 133 backend tests with 745 assertions at 86.7% application coverage and 21 browser journeys with 155 assertions.
- Pint, PHPStan, TypeScript, ESLint, Prettier, the production Vite build, fresh/rollback/reapply SQLite migrations, npm audit, and Composer audit pass. React Doctor reports 100/100 with no findings.
- The adversarial exit audit fixed nested-form submission, rapid check-off revision conflicts, reusable-action validation gaps, missing per-model policies, preference reuse, partial-total labelling, stale-diff typing, and catalogue-history mutation risks before M4 was closed.
- The post-completion audit moved stale-list protection into locked domain actions, made conflicting document and meal-move revisions mandatory while preserving merge-safe check-offs, added portable uniqueness keys for household defaults and product preferences, and completed policy coverage for meal slots, list revisions, and item sources.

The evidence above remains valid for the implemented M4 shopping domain. M4.1
adds the missing automatic recipe-preparation boundary, so the original M4
domain now also satisfies its natural-language end-to-end product exit.

## M4.1 — Natural Plan-to-Shop reliability

Status: `[x]`

Implementation brief:
[M4.1 — Natural Plan-to-Shop reliability](M4.1-PLAN-TO-SHOP-RELIABILITY.md)

- [x] Generate a structured draft recipe when an ordinary cookable meal is selected.
- [x] Retain the exact generated `RecipeVersion` on the planned meal.
- [x] Track preparation durably and make retries idempotent and observable.
- [x] Extend readiness so Plan review cannot finish with unresolved cookable meals.
- [x] Prevent shopping generation from silently omitting cookable meals without recipes.
- [x] Prepare and aggregate recipe ingredients without manual household reconstruction.
- [x] Continue the same meal-plan conversation through shopping preparation and list editing.
- [x] Replace the manual-first Shopping screen with progressive preparation, review, budget, and completion states.
- [x] Recover existing confirmed plans through an authorised queued application workflow.
- [x] Prove the natural-language Plan-to-Shop journey, failure recovery, tenancy, and responsive experience without live AI calls.

Exit evidence:

- A naturally planned ordinary meal has a usable versioned recipe before Plan review can finish.
- A confirmed natural-language plan produces a populated, traceable shopping list without manual ingredient reconstruction.
- Pantry, quantity, staple, and budget changes can be made conversationally and remain visible as structured list state.
- Existing confirmed plans can be recovered without database migrations calling OpenAI.
- Preparation failures are visible and safely retryable without duplicate recipes, revisions, or list rows.
- The complete backend, frontend, browser, migration, audit, coverage, and React Doctor gates pass and are recorded.

M4.1 acceptance evidence recorded 16 July 2026:

- Selected custom meals and accepted Chef proposals invoke one authorised recipe-preparation action. A typed Laravel AI SDK structured-output adapter sits behind Chef's `RecipeDrafter` contract, while deterministic fakes cover normal tests without OpenAI requests.
- A team-owned preparation record tracks pending, processing, completed, failed, and cancelled work. Fingerprinted recipe creation, locked materialisation, safe provider errors, and stale-job cancellation prevent duplicate or obsolete recipes and plan revisions.
- Drafting context includes household-wide truths plus only the people participating in that meal. Person-specific preferences do not leak into meals they are not attending, and explicit safety constraints remain distinct from preferences.
- Readiness, confirmation, legacy recovery, list generation, completion, and manual-recovery rules agree on the same recipe-required classification. Equivalent units such as `cup` and `cups` aggregate into one traceable row.
- Shopping exposes one useful state at a time: prepare, wait/retry, review, optional budget, and complete. It retains the plan conversation, hides manual ingredient entry outside advanced failure recovery, polls durable preparation state, and presents a usable narrow-screen layout.
- A real browser journey starts from a typed meal-planning request, accepts the proposed meal, automatically prepares its recipe, confirms the plan, opens its populated list, then uses the same conversation to mark pantry stock, add three litres of milk, and set a $180 budget. No manual recipe reconstruction or live model call is involved.
- The recovered local meal plan 1 retains seven structured recipe versions and a generated 33-row list. Its combined jasmine-rice requirement has one source-traceable row rather than singular/plural duplicates.
- Eleven focused M4.1 feature regressions cover proposal and direct selection, participant-scoped truth, explicit non-recipe states, stale queued work, readiness guards, safe failures, malformed output, SDK fakes, authorised legacy recovery and retry, tenancy, and conversational shopping mutations.
- The final Composer gate passes 145 backend tests and 826 assertions at 86.1% application coverage. All 21 browser journeys pass with 166 assertions, including the natural-language Plan-to-Shop flow and 390 × 844 Shopping coverage.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, SQLite fresh/rollback/reapply, npm audit, and Composer audit pass. React Doctor reports 100/100 with no findings, and the live desktop/narrow inspection reports no browser warnings or errors.

Shopping grouping follow-up evidence recorded 16 July 2026:

- Shopping items now retain one editable grocery category in structured state and revision snapshots. Generated recipe requirements, legacy rows, manual extras, recovery ingredients, HTTP edits, and Chef shopping tools all use the same category contract; regeneration preserves household corrections for matching requirements.
- The Shopping workspace renders category sections in a stable grocery order and exposes a `Move to` correction inside each item's existing context menu. Category labels and ordering come from the Laravel enum rather than a second client-side source of truth.
- The existing local meal plan 1 was migrated without regeneration or row loss: all 33 items were backfilled across six populated sections. Rollback and reapply retained all 33 rows and reproduced the same categorisation.
- The complete Composer gate passes 156 backend tests and 855 assertions at 86.6% application coverage. All 21 browser journeys pass with 171 assertions, including grouped Shopping coverage at desktop and 390 × 844.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, npm audit, Composer audit, and the category migration rollback/reapply pass. React Doctor reports 100/100 with no findings.

Shopping conversation resilience follow-up evidence recorded 17 July 2026:

- A request containing several household extras now invokes one strict `AddPlanShoppingItems` tool and one authorised transactional action. The entire batch validates before writing and creates one shopping-list revision.
- Each conversational row retains its source user message and a database-unique item idempotency key. Replaying the turn returns the original rows, concurrent retries are serialised by the list lock, and merge-safe additions apply to the latest revision without weakening optimistic concurrency for destructive edits.
- The OpenAI-backed mixed read/write Chef agent disables parallel tool calls. If the provider fails after a durable mutation, Chef derives a factual acknowledgement from the new list revision and completes the message instead of reporting a false failure.
- Regressions cover the reported two-item Scrub Daddy and paper-towel request, same-turn replay, a later retry after a legacy partial write, full-batch validation, concurrent-revision merging, cross-household rejection, and provider failure after a committed tool result. The browser happy path adds two extras in one conversation turn.
- The final Composer and coverage gates pass 163 backend tests and 888 assertions at 87.1% application coverage. All 21 browser journeys pass with 172 assertions.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, npm audit, Composer audit, and the new migration rollback/reapply pass. The migration retained all 35 pre-existing shopping rows; reconciling the reported partial turn then added only the missing paper towels for 36 total rows, with no foreign-key violations.

## M5 — Cooking and feedback loop

Status: `[x]`

- [x] Build the Today screen and distraction-free cooking mode.
- [x] Display ingredients, equipment, preparation warnings, substitutions, and sequential steps.
- [x] Support timers or concurrent-task cues where they materially help.
- [x] Record cooked, skipped, postponed, replaced, leftovers, and ate-out outcomes.
- [x] Capture lightweight person-specific feedback.
- [x] Turn repeated feedback into inspectable preference candidates.
- [x] Prevent inferred preferences from becoming safety constraints.

Exit evidence:

- A cook can go from the Today screen to completing and rating a meal without reading the planning conversation.
- The next recommendation can explain how prior feedback affected it.

M5 acceptance evidence recorded 17 July 2026:

- Today uses the family timezone, answers what is being cooked now, and falls forward to the next planned meal when today is empty. Recipe and non-recipe meals both expose one direct next action without requiring the planning conversation.
- The focused cooking surface shows the exact planned `RecipeVersion`, large sequential steps, ingredients, equipment, preparation notices, storage guidance, and the latest ordered product or substitution. Fullscreen and screen-wake support remain optional browser enhancements with a complete typed fallback.
- Starting cooking is idempotent and records durable current-step progress plus the cooking milestone. Recipe timers persist while moving between steps in the session, remain independently controllable, and use large arm's-length controls at 390 × 844.
- One team-scoped `MealOutcome` records cooked, skipped, postponed, replaced, cooked-with-leftovers, or ate-out results with status-specific validation. One attributed `MealFeedback` per participant captures rating, portion, effort, cost, leftovers, notes, and a recipe adjustment.
- Two consistent same-recipe ratings create an evidence-linked, confidence-labelled preference candidate. Editing the ratings withdraws a stale pending candidate; accepted candidates become ordinary feedback preferences, conflicting explicit preferences require direct resolution, and dismissed candidates do not affect later recommendation explanations.
- Feedback is accepted only for a meal that was cooked and only from its recorded participants. The feedback and candidate actions have no constraint-writing capability, and regressions prove repeated ratings cannot create or alter allergies or other safety constraints.
- The adversarial review fixed missing action-level validation, feedback attached to non-cooked outcomes, outcome changes that could orphan feedback, stale candidates after rating edits, conflicting preference acceptance, dismissed signals leaking into recommendations, and an older order line winning over the latest substitution.
- The complete Composer gate passes 183 backend tests and 996 assertions at 86.3% application coverage. All 23 browser journeys pass with 192 assertions, including the complete Today-to-cooking-to-feedback flow and the 390 × 844 cooking surface.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, isolated SQLite fresh/rollback/reapply, npm audit, and Composer audit pass. React Doctor reports 100/100 with no findings.

## M6 — Woolworths and Coles computer-use handoff

Status: `[ ]`

- [x] Implement `AutomationRun`, steps, approvals, browser connections, and reconciliation models.
- [x] Implement `ComputerUseEngine` with a fake and recorded fixtures.
- [x] Build the permissioned Chrome extension and pairing flow.
- [x] Restrict execution to an explicitly selected tab and retailer origins.
- [x] Implement the Responses API `computer_call` loop.
- [x] Broadcast progress, unresolved matches, substitutions, and failures.
- [x] Add pause, cancel, expiry, takeover, and safe resume.
- [x] Require approval at defined risk boundaries.
- [x] Reconcile intended list items with the prepared cart.
- [x] Keep checkout, address changes, authentication, and payment human-controlled.
- [ ] Validate the workflow against real Woolworths and Coles sessions without putting credentials in Chef.

Exit evidence:

- From an approved frozen list, Chef can prepare a reviewable Woolworths or Coles cart in the user's chosen tab, preserve the benefits of the user's retailer account, stop safely when uncertain, and hand control back before checkout.

M6 implementation evidence recorded 17 July 2026:

- Five team-scoped automation records now preserve the paired browser grant,
  frozen run scope, resumable action steps, just-in-time approvals, and the
  intended-to-actual cart reconciliation. Policies, current-team route binding,
  hashed one-time credentials, hard origin checks, and cross-household route and
  token regressions cover every automation resource.
- A Chef-owned direct Responses client implements the GA `computer` call and
  screenshot continuation protocol, including batched actions,
  `previous_response_id`, explicitly acknowledged safety checks, final JSON
  reconciliation, a fake engine, and recorded Woolworths and Coles fixtures.
- The Manifest V3 extension uses `activeTab`, exact retailer and Chef host
  permissions, one explicitly selected tab, a visible run badge, and a second
  local allowlist. It never receives the OpenAI key. It refuses hidden-tab
  screenshots, checks the origin after each action, preserves safe cart/trolley
  review controls, and blocks checkout, order submission, authentication,
  address, delivery-slot, and payment controls.
- The Shopping workspace creates and revokes pairings, freezes a selected list
  revision and retailer, shows calm live progress, explains approval
  consequences, and provides pause, cancellation, takeover, safe fresh-state
  resume, reconciliation, and final handoff controls. Checkout is not exposed as
  a Chef action.
- The adversarial review fixed stale safety-check acknowledgements, stale-state
  resume after manual takeover, cross-tab screenshot capture, leaked screenshots
  on racing submissions, ineffective computer-use retries, lost extension
  results that could hang forever, incomplete broadcast payloads, React-controlled
  input updates, continued action batches after boundary navigation, and a Coles
  label that combined safe trolley review with the word “checkout”. A final
  control-path pass also made pause and cancellation invalidate the active step,
  added extension control checks between every action and every 500 ms of a
  wait, serialized overlapping polls, and preserved a five-minute watchdog as
  the last-resort recovery path.
- Twenty-four focused M6 feature scenarios pass with 116 assertions. The full
  Composer and coverage gates pass 207 backend tests with 1,112 assertions at
  84.9% application coverage. The complete
  browser suite passes 25 journeys with 209 assertions, including desktop run
  controls and explicit approval/reconciliation at 390 × 844. Extension policy
  checks pass four scenarios.
- Pint, PHPStan, ESLint, Prettier, TypeScript, the production Vite build, the
  Astro marketing build, and React Doctor pass; React Doctor reports 100/100.
- Current public Woolworths and Coles sessions were inspected at their live
  origins. Both expose the expected product search, retailer product, cart or
  trolley, account, address, and delivery controls. Search reached current 2 L
  full-cream-milk products at both retailers without transmitting credentials.
  Anonymous sessions did not retain an add-to-cart mutation without shopping
  setup, and a follow-up check of the existing Chrome profile found Woolworths
  signed out. The final signed-in, unpacked-extension cart run therefore remains
  the one open M6 exit check. Chef will not store or request the retailer
  credentials for that check.

## M7 — Native voice experience

Status: `[ ]`

- [ ] Implement a Laravel endpoint for scoped Realtime session creation.
- [ ] Connect the React composer to OpenAI Realtime over WebRTC.
- [ ] Route voice-triggered tools through authorised Chef domain actions.
- [ ] Persist transcripts, tool actions, and artifacts into the same conversation as typed messages.
- [ ] Support interruption, reconnect, mute, and graceful typed fallback.
- [ ] Make microphone permission just-in-time and revocable.
- [ ] Test voice states without requiring live API calls in the normal suite.

Exit evidence:

- A person can begin or continue an existing plan by voice and see the same structured plan updates that typing would produce.

## M8 — MCP and external-agent access

Status: `[ ]`

- [ ] Implement authenticated Laravel MCP transport.
- [ ] Expose team context, plans, recipes, lists, budgets, and feedback as narrow read tools.
- [ ] Add reviewed write tools for planning and feedback.
- [ ] Add a scoped cart-preparation handoff tool without exposing checkout.
- [ ] Make writes idempotent where retries are plausible.
- [ ] Document authentication, scopes, and examples for ChatGPT and other MCP hosts.

Exit evidence:

- An authorised external agent can inspect and update the same Chef plan without scraping the UI or bypassing team policies.

## M9 — Public version 1 hardening and launch

Status: `[ ]`

- [ ] Decide and validate the production database and hosting topology.
- [ ] Add production queues, scheduling, broadcasting, object storage, and backups.
- [ ] Add error reporting, product analytics, AI usage metrics, and automation audit visibility.
- [ ] Establish screenshot and conversation retention controls.
- [ ] Complete privacy policy, terms, account export, team deletion, and consent management.
- [ ] Complete accessibility review for onboarding, planning, shopping, and cooking.
- [ ] Test desktop and mobile-width core journeys.
- [ ] Test slow, interrupted, failed, and rate-limited AI workflows.
- [ ] Add abuse controls, quotas, rate limits, and cost ceilings.
- [ ] Conduct a security and tenancy review.
- [ ] Validate backups and document restoration.
- [ ] Prepare onboarding help, support flow, release notes, and public landing page.
- [ ] Run a private family beta and close all release-blocking findings.
- [ ] Tag and publish version 1.

Release gates:

- all previous milestone exit evidence remains true in the production-like environment;
- no known critical or high-severity security issue remains open;
- tenant isolation, backups, restore, and deletion are proven;
- the first-plan a-ha journey and both retailer handoffs have measured success criteria;
- operating cost and automation failure rates are understood and bounded.

## After version 1

Candidates, not commitments:

- additional retailers beyond Woolworths and Coles;
- managed cloud-browser execution;
- pantry inference from orders and receipts;
- calendar integrations;
- native mobile applications;
- advanced collaborative editing;
- household nutrition integrations;
- recipe sharing and discovery;
- multi-retailer optimisation.
