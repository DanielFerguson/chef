# Chef

Chef is a conversation-led household meal-planning application.

The current product follows one focused journey:

1. talk through the coming days or week;
2. turn that conversation into a visible, editable meal plan;
3. approve the complete plan once;
4. prepare any missing recipes as one reliable batch;
5. turn the recipes into a retailer-neutral grocery plan;
6. prepare and verify a Coles basket under revocable standing consent;
7. hand checkout back to the household, then cook and record what happened.

Chef is not a recipe catalogue with a chat box attached. Plans, people,
participation, explicit safety constraints, preferences, recipe versions,
cooking progress, outcomes, and feedback are durable application data. The
assistant operates that data through authorised tools; conversation state is
never the sole source of truth.

## Product boundary

Chef now includes a new post-reset plan-to-basket implementation. It builds a
versioned grocery plan from approved recipe versions, discovers and validates
Coles products, replaces the existing basket with a restorable baseline, and
shows the verified result in Chef. The implementation is Coles-first behind a
provider-neutral contract.

This surface is disabled by default. Public enablement requires independent
legal and privacy approval, hosted PostgreSQL and Redis worker evidence, a live
Coles pilot, real-device validation of native authentication and accessibility,
and an operable mutation circuit breaker. Coles account terms and online-safety guidance
make this a release boundary, not a box that code alone can tick.

Chef never selects fulfilment, checks out, pays, handles restricted products,
or places an order. Those actions remain human-controlled in Coles. Historical
shopping and order routes removed in July 2026 remain deleted; the new models
and interfaces do not restore that compatibility surface.

The separate marketing project remains intentionally future-facing. Its claims
must not be treated as evidence of current product capability.

## Product principles

- Conversation owns intent, negotiation, and explanation.
- Structured UI owns visible, editable state.
- Onboarding is the household's first real plan, not a questionnaire.
- A `Team` is the family or household tenancy boundary.
- A `User` is an authenticated account; a `Person` is someone who participates
  in meals. One user may belong to several teams.
- Participation and servings belong to individual meal slots.
- Allergies and other safety constraints are explicit and are never inferred.
- Preferences normally belong to people and remain distinguishable from safety
  constraints.
- Plans can span any date range.
- Recipes are versioned; a planned meal retains the exact version used.
- Feedback may produce inspectable preference candidates, never automatic
  safety rules.

## The retained journey

### Plan together

A household can describe who is eating, time constraints, recent meals,
preferences, leftovers, and the shape of the week in ordinary language. Chef
creates dated meal slots and reviewable proposals while Calendar and List views
show the same durable plan.

Direct controls remain available for selecting, moving, replacing, and editing
meals. Typed interaction is complete even when voice is unavailable.

### Approve, prepare recipes, and build the basket

The plan presents `Approve plan & prepare recipes` before Coles is connected
and `Approve plan & prepare Coles basket` when a standing grant exists.
Before that action appears, Chef resolves omitted participants from an earlier
approved matching slot or the current household, marks those values as visible
provisional suggestions, and bundles any genuine blockers into one planning
clarification. The approval brief is built from durable state and shows every
effective meal, participant and serving, explicit constraint, estimate, basket
target, grocery policy, and the effect of standing consent. Approval:

- accepts the sole displayed pending proposal for each open slot or filled-slot
  replacement;
- records the current explicit safety context;
- confirms the plan;
- dispatches one idempotent batch for custom meals without recipes;
- creates one basket run, waiting for the Coles account owner if necessary.

Recipe preparation remains one atomic structured invocation. Its input combines
authoritative plan and household state with bounded, source-linked riff
excerpts; there is no separate summarisation call. An invalid or incomplete
response does not leave a partially materialised week.

After recipe materialisation commits, Chef deterministically scales and
combines required ingredients, discovers bounded Coles candidates, applies
hard safety and availability gates, and uses one batched ranking invocation
only for remaining ambiguity. Household and plan grocery policy is applied
within explicit price ceilings, while the balanced 10% price/waste pack rule
remains deterministic. Discovery uses two deterministic searches and at most
one validated AI recovery query per unresolved requirement. Basket mutation
starts only when every requirement has a valid selection and the selected
subtotal is within the effective basket target or the Coles account owner has
explicitly accepted that run's overage.

If a product remains unavailable, or a cheaper coherent plan is requested,
Chef drafts one reviewable whole-plan adjustment without changing the approved
plan. Reapproval accepts that displayed diff and starts a fresh versioned run;
there is no automatic adjustment loop. A verified basket may show up to three
still-valid alternatives per item, and `Prefer next time` records an explicit
preference without changing the current basket.

The original basket is captured before replacement. Chef verifies every
absolute quantity from the actual Coles basket, attempts restoration after a
partial failure, and labels any unverified result as uncertain rather than
ready.

### Cook and learn

Recipe pages preserve ingredients, ordered steps, equipment, timings,
preparation notices, storage guidance, and immutable versions. Cooking mode
keeps the current step prominent, persists progress, supports timers, records
meal outcomes, and collects optional person-specific feedback.

Repeated feedback can become a reviewable preference candidate. A household
member must accept it before it becomes an ordinary preference.

## Architecture

Chef is a Laravel monolith with Inertia and React:

- Laravel owns authentication, tenancy, policies, actions, queues, durable
  state, and server-side AI credentials.
- React owns the conversational workspace, Calendar and List views, recipes,
  grocery progress, basket review, cooking mode, and settings.
- The native iOS client uses the same authorised Laravel actions through a
  versioned mobile API; it includes authoritative approval review, grocery
  policy and targets, alternatives, coherent recovery, Coles connection,
  foreground polling, basket review/restoration, and owner handoff without
  reimplementing safety rules.
- Meaningful mutations live in reusable action classes used by controllers and
  AI tools.
- The Laravel AI SDK provides typed agents, tools, structured output, streaming,
  middleware, events, and fakes behind Chef-owned interfaces.
- Long-running recipe preparation runs on the `ai` queue and is safe to retry.
- Grocery, discovery, selection, replacement, and restoration use dedicated
  jobs; browser work runs on the `retailer` queue.
- Browserbase Contexts retain the remote Coles session. Context and session
  identifiers are encrypted; Live View URLs and browser observations are
  transient.
- SQLite remains the local default for ordinary development and deterministic
  tests. Live retailer automation requires PostgreSQL and Redis.

See [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md) for technical boundaries,
[docs/MILESTONES.md](docs/MILESTONES.md) for delivery truth, and
[docs/STYLE.md](docs/STYLE.md) for interface direction.

## Repository

```text
chef/
├── docs/       Product, architecture, milestones, style, and brand
├── ios/        Native SwiftUI client and its local Swift packages
├── marketing/  Future-facing public vision; intentionally separate
└── web/        Laravel, Inertia, React, tests, and migrations
```

## Local setup

Requirements:

- PHP 8.4 or later
- Composer
- Node.js and npm
- Xcode and XcodeGen for the native client
- Playwright browser binaries for the browser test suite

From `web/`:

```bash
composer setup
composer dev
```

Copy required credentials into `.env`. OpenAI credentials stay server-side.
Normal automated tests use fakes and do not call live models.

Playwright is retained only as development tooling for Pest Browser. Runtime
retailer automation uses the versioned Stagehand worker, not the test browser.

## Quality gates

From `web/`:

```bash
# Fast local backend gate: lint, PHPStan, then Pest's impacted tests.
composer test

# Rebuild the local impact graph after first install or when it becomes stale.
composer test:tia:fresh

# Deterministic full backend and release gate.
composer test:full

# Complete backend coverage with an enforced 80% floor.
composer test:coverage

# Required browser journeys in Chromium and Safari/WebKit.
composer test:browser:happy
composer test:browser:happy:safari

# Mutation quality for approval, product selection, and basket replacement.
composer test:mutation:critical

# PostgreSQL/Redis/external-worker gate; skips without that infrastructure.
composer test:integration:ci

npm run lint:check
npm run format:check
npm run types:check
npm run build
npm run automation:test
npm run automation:audit
composer audit --locked

swift test --package-path ../ios/Packages/ChefAPI
xcodegen generate --spec ../ios/project.yml
xcodebuild -project ../ios/Chef.xcodeproj -scheme Chef \
  -destination 'platform=iOS Simulator,OS=latest,name=iPhone 16 Pro' test
```

`retailer:readiness --json`, `retailer:circuit-breaker`, and
`retailer:metrics --since=24h --json` are the operator interfaces for the
PostgreSQL/Redis worker, fail-closed runtime kill switch, and privacy-safe pilot
evidence. They never contact Coles, Browserbase, Stagehand, or an AI provider.

`composer test` and `composer test:browser` use Pest 5 Test Impact Analysis
locally. The dependency graph lives in Pest's user cache and is not committed.
Coverage and CI always run the complete suite with `--ci --no-tia`;
`composer test:browser:ci` runs the complete Chromium suite, while the two
`test:browser:happy` commands require the product-critical journeys in both
Chromium and Safari/WebKit. The ordinary PHPUnit discovery boundary contains
only Unit and Feature tests; Browser, Integration, and live Evals are explicit
workloads and cannot leak into the fast backend gate.
Direct Pest invocations, including `./web/vendor/bin/pest` from the repository
root, run the full suite without TIA. Use the Composer commands when local TIA
is wanted because their launcher aligns Pest with Chef's parent Git root.

The required browser group exercises real registration, conversation-backed
planning, whole-plan approval, recipe and cooking completion, feedback, first
Coles consent, and standing-consent basket preparation. It checks durable
database facts, accessibility, JavaScript/console smoke, and desktop plus
iPhone-sized layouts. CI separately runs the recorded retailer fixture through
PostgreSQL, Redis, and an external queue worker. That integration test skips
locally unless all three services are deliberately configured.

CI publishes one `Required test gate` result over backend coverage, frontend,
PostgreSQL/Redis infrastructure, browsers, critical mutation testing, and iOS.
Repository branch rules should require that single result before merge. The
current private repository plan does not expose protected branches, so the
workflow reports one truthful aggregate but cannot itself prevent an authorised
user from merging around it.

Pest Agent is available for disposable diagnostic probes through
`vendor/bin/pest --agent='<PHP assertions>'`. Probes inherit Chef's Laravel
test case and isolated database, but durable behaviour must be converted into a
committed regression test.

The reviewed live AI suite is separate from normal tests:

```bash
OPENAI_API_KEY=... composer test:evals
```

The suite currently makes twelve workload prompts and eighteen scoring
requests, plus any tool-loop turns. It covers Chef's conversational tool and
safety boundary plus recipe drafting, plan adjustment, product ranking, and
search recovery. It therefore incurs OpenAI usage; actual cost depends on the
configured conversation and
`PEST_EVALS_LARAVEL_SCORING_MODEL` models. It is advisory and runs only by
manual dispatch or the weekly fail-closed workflow, never as a pull-request
gate. Normal tests skip `tests/Evals` before constructing a fixture or calling
a provider; deterministic prompt-contract assertions remain in the ordinary
Unit suite.

Chef's repository-wide rules live in root `AGENTS.md`. Laravel Boost is shared
through `web/boost.json`, generated `web/AGENTS.md`, root `.agents/skills`, and
root `.codex/config.toml`. Trust this repository and restart Codex after
installation or Boost configuration changes so the project-local MCP is loaded
from `web/`. Boost exposes read-only application, documentation, route, log,
schema, and database-query tools; Tinker and rule-writing are disabled. Chef's
hand-written rules remain authoritative over scoped generated guidance.

Behavioural changes require focused domain and authorisation coverage; workflow
changes also require desktop and narrow-screen browser coverage.
