# Chef milestones

Status: active delivery truth after the July 2026 platform reset.

Chef's current finish line is:

> A household can talk through a real week, see and edit the resulting plan,
> approve it once, receive a complete set of recipes, have Chef prepare and
> verify a restorable Coles basket, retain human control of checkout, cook, and
> feed outcomes back into later planning.

Milestone status is supported by code and tests, not design intent.

## M0 — Product and architecture

Status: complete.

- [x] Define conversation as the primary intent interface and structured UI as
  the durable state interface.
- [x] Define team tenancy and the `User` / `Person` distinction.
- [x] Define explicit safety constraints and evidence-aware preferences.
- [x] Keep Chef as a Laravel monolith with Inertia and React.
- [x] Establish product, implementation, milestone, style, and brand sources of
  truth.

## M1 — Foundation and households

Status: complete.

- [x] Authentication, passkeys, profile, security, and team membership.
- [x] Family creation, invitations, current-team switching, and tenant-scoped
  route binding.
- [x] People, meal participation, preferences, and explicit constraints.
- [x] Application shell, responsive navigation, shared design tokens, and
  quality gates.

## M2 — Conversational first plan

Status: complete.

- [x] Onboarding creates the household's first actual plan.
- [x] Durable conversations, messages, streaming, retries, and feedback.
- [x] Private multimodal planning turns with normalized photo uploads,
  idempotent retries, durable transcript attachments, and retained AI context.
- [x] Dated meal slots, participants, proposals, acceptance, rejection, and
  direct selection.
- [x] Authorised AI tools invoke the same domain actions as controllers.
- [x] Calendar and List show the same editable plan state.
- [x] Complete plans expose one whole-plan approval action.
- [x] `ConfirmPlan` uses the Laravel AI SDK persisted human-approval pause and
  resume flow while the same authoritative card preserves direct approval.

Exit evidence includes feature coverage for normalization, validation,
idempotency, tenancy, private delivery, cleanup, workspace serialization, and
current/prior Laravel AI SDK attachments. Normalization evidence covers the
Laravel image pipeline's orientation, no-upscale 2560 px limit, optimized JPEG
encoding, metadata removal, transparent-input background, native HEIC/HEIF
decoding through a `libheif`-enabled Imagick runtime, and legacy WebP delivery.
Approval coverage includes pause, approve, reject,
stale/cross-conversation decisions, ledger cleanup, and the direct fallback.
Desktop and 390 × 844 browser coverage exercises the shadcn attachment picker,
client-side HEIC preview, persisted transcript controls, and persisted approval
card.

## M3 — Recipes and complete planning

Status: complete.

- [x] Canonical ingredients, recipes, immutable recipe versions, imports,
  ingredients, steps, equipment, notices, and storage guidance.
- [x] Planned meals retain the exact recipe version used.
- [x] Plans support arbitrary ranges, revisions, moving meals, and explicit
  non-recipe meal types.
- [x] `ApproveMealPlan` accepts sole pending proposals, records safety,
  confirms the plan, and starts one idempotent recipe batch.
- [x] Recipe batches validate exact-once coverage and materialise atomically.
- [x] Preparation exposes prepare, wait, retry, and ready states.
- [x] Failed batches can be retried without creating partial recipe data.

Exit evidence:

- Focused approval coverage proves final-proposal acceptance, safety
  confirmation, one queued batch, repeat-call idempotency, team isolation,
  atomic materialisation, and failed-batch retry.
- Plan and recipe UI states are covered at desktop and narrow widths.
- Full release-gate results are recorded in the implementation handoff rather
  than frozen permanently in this document.

## M4 — Cooking and feedback

Status: complete.

- [x] Today selects the relevant planned meal and falls forward when needed.
- [x] Cooking mode persists progress, supports timers, and keeps recipe truth
  close at hand.
- [x] Outcomes cover cooked, skipped, postponed, replaced, leftovers, and
  eating out.
- [x] Feedback is optional and participant-specific.
- [x] Repeated feedback produces inspectable candidates, not implicit safety
  rules.
- [x] Candidate review can promote an ordinary preference without overriding
  explicit household truth.

## N1 — Native riff and first plan

Status: in progress.

The mobile API foundation exists, but the earlier milestone overstated a native
planning client that was not present. The checked items below now describe only
code that exists.

- [x] Establish a versioned, tenant-scoped mobile API over shared domain
  actions.
- [x] Support device tokens, expiry, revocation, registration, and Fortify
  two-factor challenges in the API.
- [x] Expose first-plan creation, durable workspace loading, conversation
  streaming, participant edits, proposal decisions, safety review, and plan
  approval through `/api/v1`.
- [x] Scaffold the SwiftUI application and testable `ChefAPI` local package.
- [x] Implement native sign-in and TOTP challenge with Keychain token storage.
- [ ] Add native registration and recovery-code entry.
- [ ] Stream a real planning turn and reconcile it with the durable workspace.
- [ ] Present native Conversation and List projections with proposal,
  participant, safety, and approval controls.
- [ ] Add the Calendar projection without reducing the typed workflow.
- [ ] Verify Dynamic Type, VoiceOver, reconnect, retry, revision-conflict, and
  narrow iPhone behaviour.

Exit evidence:

- API tests prove token lifecycle, two-factor enforcement, first-plan creation,
  streaming persistence, mobile mutations, and cross-household isolation.
- Swift package tests prove the contracts the package actually implements,
  including authenticated requests, decoding, 2FA mapping, and safe errors.
- One simulator UI journey creates or resumes a first plan, riffs with Chef,
  reviews the resulting durable plan, and reaches whole-plan approval.
- Backend, frontend, Swift, and simulator quality gates pass.

## M5 — Web plan-to-Coles basket beta

Status: implemented behind disabled flags; release gates in progress.

- [x] Keep recipe preparation as one atomic structured invocation with
  authoritative state and bounded, source-linked riff excerpts.
- [x] Start one idempotent basket run from the unchanged approval request body.
- [x] Add post-reset retailer connections, standing grants, versioned grocery
  plans/sources, candidates, selections/preferences, runs, snapshots, and
  database notifications.
- [x] Scale servings, exclude optional ingredients and water, combine compatible
  units, preserve every recipe source, and fingerprint derived state.
- [x] Apply Coles origin/SKU/stock/pack/price/restricted-product/safety-evidence
  gates before one bounded AI ranking batch.
- [x] Enforce the balanced 10%-price/waste pack policy and reject invalid
  ranker candidate IDs.
- [x] Implement `chef.retailer.v1`, deterministic locators, bounded Stagehand
  recovery, allowed domains, Australian sessions, and disabled recording/logging.
- [x] Capture a baseline, revalidate, replace with absolute idempotent commands,
  inspect after lost responses, detect concurrent edits, and restore after
  partial failure.
- [x] Add calm planning progress, dedicated basket review, confirmed/uncertain
  copy, restoration, owner-only Live View handoff, and in-app notifications.
- [x] Add matching web and `/api/v1` connection, basket, retry, restoration,
  review, notification, Live View release, and mobile-input routes.
- [x] Cover aggregation, selection, consent, ownership/tenancy, mutation,
  restoration, API state, worker protocol, desktop, and 390×844 surfaces with
  deterministic tests.
- [ ] Complete independent Coles contractual/legal and privacy review.
- [ ] Run the live Coles pilot for a non-empty basket, product/pack ambiguity,
  stock changes, 2FA, Context reuse, persistence, and restoration.
- [x] Add PostgreSQL/Redis CI integration, a harmless Redis queue/readiness
  probe, safe completion/error/restore metrics, and an immediately operable
  fail-closed runtime mutation kill switch.
- [x] Remediate the production automation dependency family to zero reported
  advisories in the pruned deployed graph and omit unused optional providers.
- [ ] Prove the PostgreSQL/Redis worker, metrics, kill switch, and cohort flags
  in the hosted deployment.

Exit evidence:

- All normal tests use AI and retailer fakes and prevent stray network access.
- The live pilot is manual and never runs in ordinary CI.
- Public enablement remains false until the three unchecked release gates have
  durable evidence. Code completion alone does not make the beta public.

## M5.1 — Frictionless plan-to-Coles preparation

Status: web and native interaction implementation complete behind disabled
flags; external public-release evidence remains open.

- [x] Add deterministic readiness blockers, one bundled Chef clarification, and
  an authoritative approval brief.
- [x] Resolve omitted participants with visible history/household provenance;
  keep allergies and safety constraints explicit.
- [x] Atomically approve displayed filled-slot replacements and preserve
  exact-once recipe dispatch and rollback.
- [x] Probe standing-grant authentication alongside recipe work and enforce one
  connection-level actor lock across every retailer session purpose.
- [x] Add household and plan purchasing policy, immutable policy snapshots,
  semantic tiers, the 15% preference ceiling, bulk avoidance, and the fixed
  balanced 10% pack rule.
- [x] Add explicit `Prefer next time` alternatives and revocation, plus two
  deterministic searches followed by one validated AI/fallback query.
- [x] Add one-loop, idempotent plan-adjustment drafts for unavailable products
  and budget overruns, with owner-only run overrides before mutation.
- [x] Add stable public states, calm active copy, actionable-only notifications,
  safe transition durations, and bounded Stagehand fallback instrumentation.
- [x] Add matching web and `/api/v1` policy, preference, and budget-override
  operations through Wayfinder.
- [x] Add optional Swift public-state/attention decoding and presentation for
  both new internal statuses.
- [x] Cover the domain, policies, jobs, HTTP contracts, desktop, and 390×844
  web journeys with Pest 5 and deterministic retailer/AI fakes.
- [x] Add the full additive `ChefAPI` contract and native approval brief,
  purchasing-policy, plan-target, alternatives, preference revocation, budget,
  and unavailable-product review interactions.
- [x] Add simulator unit/UI tests and CI coverage for policy settings, approval
  review, target editing, coherent recovery, alternatives, and the additive
  request/decoding contracts using network-free fixtures.
- [ ] Complete the M5/N2 real-device, hosted PostgreSQL/Redis, legal/privacy,
  circuit-breaker, and live Coles pilot gates before public beta.

The AI recovery flag defaults off. M5.1 does not authorise inferred allergies,
automatic plan replacement, budget bypass, fulfilment, restricted products,
checkout, payment, or ordering.

## N2 — Native basket preparation

Status: implemented through simulator tests; real-device gates in progress.

- [x] Add a real SwiftUI target and local `ChefAPI` package against `/api/v1`.
- [x] Add foreground-only plan/basket polling and in-app notification
  acknowledgement without push.
- [x] Add Coles connection, disclosure/standing consent, progress, basket
  details, retry, restoration, and owner-only review surfaces.
- [x] Embed short-lived Live View in a non-persistent `WKWebView`.
- [x] Add a bounded owner-only input relay that accepts no more than 256
  characters or one allowlisted key, never persists or echoes it, and releases
  the active session explicitly.
- [x] Pass `ChefAPI` package tests, generic simulator compilation, and native
  unit tests for confirmed/uncertain status presentation.
- [x] Decode optional M5.1 public state, outcome, attention, and the two new raw
  orchestration statuses without breaking staged older payloads.
- [x] Add native M5.1 approval brief, policy and target editing, alternatives,
  preference revocation, and coherent owner/non-owner recovery screens.
- [ ] Exercise Coles authentication, 2FA, reconnect, review, and input relay on
  at least one real iPhone.
- [ ] Complete Dynamic Type and VoiceOver journeys for connection, progress,
  basket review, restoration, and errors.
- [ ] Complete a native end-to-end live pilot against the same release cohort
  and circuit breaker as web.

Native support is required before the complete public beta can be declared,
but these unchecked device/security gates do not block the web implementation
from remaining available to an internal mutation pilot.

## Q1 — Controllable happy-path evidence

Status: repository implementation complete; host merge enforcement is blocked
by the current GitHub plan, and external/live evidence remains separate.

- [x] Restrict ordinary Pest discovery to deterministic Unit and Feature tests;
  freeze time, prevent stray HTTP, and fail closed on stray prompts for all five
  Laravel AI SDK agents.
- [x] Require one grouped browser contract from real registration through
  planning, approval, recipes, Calendar/List, cooking, outcome, and feedback at
  desktop and iPhone-sized widths.
- [x] Require first Coles Live View consent and standing-consent
  discovery/replacement/review journeys with durable facts, accessibility, and
  browser-smoke assertions.
- [x] Run the complete browser suite in Chromium and repeat the happy-path group
  in Safari/WebKit.
- [x] Exercise the recorded retailer pipeline through PostgreSQL, Redis, and a
  separately started queue worker in CI without external retailer/provider
  traffic.
- [x] Enforce 80% backend coverage and an 80% mutation floor for approval,
  product selection, and basket replacement.
- [x] Bake deterministic prompt-contract assertions into normal tests and run
  fail-closed live evals for Chef plus every structured prompt workload on a
  separate manual/weekly workflow.
- [x] Publish one CI `Required test gate` over backend, frontend,
  retailer-infrastructure, browser, mutation, and iOS jobs.
- [ ] Require `Required test gate` in GitHub branch rules. On 2026-08-01 the
  protection API returned `403`: this private repository requires GitHub Pro,
  Team, Enterprise, or public visibility before protected branches are
  available.

Pixel screenshot baselines are not part of the required gate yet. The current
visual contract uses accessibility, overflow, visibility, interaction, and
desktop/narrow browser assertions. A future pixel gate should generate and
review its first baseline on the same Linux browser image used by CI rather
than commit a macOS baseline that produces cross-platform noise.

Live OpenAI quality, Browserbase/Coles behaviour, cold production workers,
hosted PostgreSQL/Redis operations, real-device iOS behaviour, and external
legal/privacy approval remain explicitly outside this deterministic evidence.

## Later milestones

These remain candidates, not current completion claims:

- Native voice over the same durable conversation and domain actions.
- Narrow external read/write tools for approved household workflows.
- Production privacy, operations, support, observability, backup, and public
  release gates.

## Definition of done

A milestone is complete only when:

- its end-to-end household journey works;
- domain invariants and cross-team isolation are tested;
- retryable work is idempotent and failure-safe;
- desktop and narrow-screen behaviour are verified;
- backend and frontend quality gates pass;
- documentation matches the shipped boundary;
- any live external evidence required by the milestone actually exists.
