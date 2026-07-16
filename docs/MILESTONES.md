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
6. prepare a Coles cart through reviewed computer use;
7. use typed and voice conversation;
8. invite another account into the family team;
9. provide feedback that improves later recommendations.

Public release additionally requires secure tenancy, accessible core journeys, operational monitoring, documented privacy behaviour, recovery procedures, and a supportable deployment.

## M0 — Product and architecture definition

Status: `[~]`

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
- [ ] Commit the documentation baseline.

Exit evidence:

- README and supporting documents agree on terminology, version 1 scope, and architecture.
- Remaining unknowns are explicitly deferred rather than accidentally implied.

## M1 — Application foundation and team tenancy

Status: `[ ]`

- [ ] Scaffold Laravel 13 with the React/Inertia starter kit.
- [ ] Configure TypeScript, Tailwind, shadcn/ui, Pest, formatting, and static analysis.
- [ ] Establish the Chef application shell and responsive navigation.
- [ ] Implement authentication.
- [ ] Implement `Team`, `TeamMembership`, `TeamInvitation`, `Person`, and `UserPersonLink`.
- [ ] Implement active-team selection and team-scoped route bindings.
- [ ] Add policies and cross-team isolation tests.
- [ ] Add seeded local accounts and a representative family fixture.
- [ ] Establish CI for backend tests, frontend checks, and production builds.

Exit evidence:

- Two users can belong to one team and see the same empty Chef workspace.
- A user cannot read or mutate another team's records by changing identifiers.
- Desktop and narrow-screen shells pass a focused browser test.

## M2 — First-plan conversational onboarding

Status: `[ ]`

- [ ] Implement `MealPlan`, `MealSlot`, participant, conversation, message, preference, and constraint models.
- [ ] Install and wrap the Laravel AI SDK behind `ChefConversationEngine`.
- [ ] Implement the initial `ChefAgent` and read/write domain tools.
- [ ] Stream typed assistant responses into the conversation.
- [ ] Build conversational onboarding without a separate wizard.
- [ ] Build the live plan and household inspector.
- [ ] Support structured meal proposals and direct accept, reject, replace, and move actions.
- [ ] Show an editable summary separating safety rules, stated preferences, defaults, and inferences.
- [ ] Persist and resume the first plan and conversation.
- [ ] Allow an invited second member to make an attributed plan change.
- [ ] Add deterministic agent fakes and an end-to-end browser test.

Exit evidence — first a-ha moment:

> A new user can describe their family and next few days in natural language, receive a relevant plan, adjust it conversationally or visually, invite another person, and return later to the same durable workspace.

## M3 — Recipes and complete planning workspace

Status: `[ ]`

- [ ] Implement recipes, versions, ingredients, steps, equipment, and preparation notices.
- [ ] Support recipe creation and a minimal import workflow.
- [ ] Support arbitrary date spans and breakfast, lunch, dinner, snack, and custom slots.
- [ ] Support leftovers, eating out, takeaway, skipped meals, and open slots.
- [ ] Build plan calendar and list views with drag-and-drop scheduling.
- [ ] Add participant and serving overrides per slot.
- [ ] Add plan milestones, revision tracking, and stale-derived-data detection.
- [ ] Explain recommendations using current constraints, recency, cost, and effort.

Exit evidence:

- A family can create and revise a complete one- or two-week plan without losing the recipe version or participant context attached to each slot.

## M4 — Cooking and feedback loop

Status: `[ ]`

- [ ] Build the Today screen and distraction-free cooking mode.
- [ ] Display ingredients, equipment, preparation warnings, substitutions, and sequential steps.
- [ ] Support timers or concurrent-task cues where they materially help.
- [ ] Record cooked, skipped, postponed, replaced, leftovers, and ate-out outcomes.
- [ ] Capture lightweight person-specific feedback.
- [ ] Turn repeated feedback into inspectable preference candidates.
- [ ] Prevent inferred preferences from becoming safety constraints.

Exit evidence:

- A cook can go from the Today screen to completing and rating a meal without reading the planning conversation.
- The next recommendation can explain how prior feedback affected it.

## M5 — Shopping lists and budgets

Status: `[ ]`

- [ ] Aggregate recipe requirements and normalise units.
- [ ] Implement structured `ShoppingListItem` records and list revisions.
- [ ] Support manual staples, pantry exclusions, include/exclude state, and annotations.
- [ ] Build a fast document-like shopping-list editor backed by structured rows.
- [ ] Implement retailers, retail products, product preferences, and product matches.
- [ ] Implement plan and household budget defaults and overrides.
- [ ] Store estimated and actual order totals as historical snapshots.
- [ ] Mark shopping lists stale and show diffs when a confirmed plan changes.

Exit evidence:

- A family can generate, edit, share, and complete a trustworthy list whose quantities remain traceable to meals or manual requests.

## M6 — Coles computer-use handoff

Status: `[ ]`

- [ ] Implement `AutomationRun`, steps, approvals, browser connections, and reconciliation models.
- [ ] Implement `ComputerUseEngine` with a fake and recorded fixtures.
- [ ] Build the permissioned Chrome extension and pairing flow.
- [ ] Restrict execution to an explicitly selected tab and retailer origins.
- [ ] Implement the Responses API `computer_call` loop.
- [ ] Broadcast progress, unresolved matches, substitutions, and failures.
- [ ] Add pause, cancel, expiry, takeover, and safe resume.
- [ ] Require approval at defined risk boundaries.
- [ ] Reconcile intended list items with the prepared cart.
- [ ] Keep checkout, address changes, authentication, and payment human-controlled.
- [ ] Validate the workflow against a real Coles session without putting credentials in Chef.

Exit evidence:

- From an approved frozen list, Chef can prepare a reviewable Coles cart in the user's chosen tab, stop safely when uncertain, and hand control back before checkout.

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
- the first-plan a-ha journey and Coles handoff have measured success criteria;
- operating cost and automation failure rates are understood and bounded.

## After version 1

Candidates, not commitments:

- Woolworths and additional retailers;
- managed cloud-browser execution;
- pantry inference from orders and receipts;
- calendar integrations;
- native mobile applications;
- advanced collaborative editing;
- household nutrition integrations;
- recipe sharing and discovery;
- multi-retailer optimisation.
