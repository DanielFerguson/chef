# Chef implementation architecture

Status: active source of truth for the post-reset Coles public-beta build.

Chef is a Laravel monolith using Inertia and React. The implemented product
boundary is conversation-led planning, recipe preparation, recipes, cooking,
outcomes, feedback, retailer-neutral grocery planning, and reversible Coles
basket preparation. Checkout, payment, fulfilment, restricted products, and
order placement remain outside Chef.

## Plan approval and recipe preparation

`ApproveMealPlan` is the sole plan-level approval transition. It:

1. authorises the current user against the plan;
2. resolves omitted meal participants through `ResolveMealSlotParticipants`;
3. requires every slot to have one effective meal and allows exactly one
   pending proposal on a filled slot as its displayed replacement;
4. accepts those proposals through `AcceptMealProposal`;
5. confirms the plan through `ConfirmMealPlan`, which records the current
   safety context;
6. invokes `PrepareMealPlanRecipes`;
7. creates one idempotent `BasketRun` through
   `StartBasketRunForApprovedPlan`.

`AssessMealPlanReadiness` returns structured blockers for missing meals,
participants, invalid servings, unresolved safety confirmation, conflicting
proposals, and stale plan state. `ChefAgent` receives these blockers in its
existing planning context and asks one bundled clarification when required;
there is no plan-analysis invocation. `BuildMealPlanApprovalBrief` projects the
effective meals, participants, servings, proposal estimates, explicit
constraints, provisional sources, basket target, purchase policy, and standing
grant effect from authoritative state.

Participant omission never affects allergy or safety truth. Explicit values
win; otherwise Chef copies the latest earlier approved slot with the same
weekday and meal kind after removing people no longer in the household, then
falls back to one serving for every current participant. Each slot records
`explicit`, `provisional_history`, or `fallback_household` provenance and an
optional source slot. Editing a suggestion makes it explicit.

`POST /meal-plans/{mealPlan}/approve` keeps its existing empty request body.
The server consults any standing retailer grant; the client cannot smuggle a
retailer, fulfilment, or checkout instruction into approval.
`POST /meal-plans/{mealPlan}/recipes/prepare` remains the explicit recipe retry
and recovery endpoint.

The same approval card also handles Laravel AI SDK human tool approval when
`ChefAgent` requests `ConfirmPlan`. `ConfirmPlan` is the only approvable agent
tool and binds its request to the exact visible `plan_revision`. Calling it
pauses the SDK run; the request is not consent and cannot mutate plan state.
The stream then accepts only the projected call ID and an `approve` or `reject`
decision. Approval resumes the SDK ledger and invokes this same
`ApproveMealPlan` action; rejection leaves the plan unchanged. If there is no
pending SDK request, the card continues to use the direct approval endpoint.
Stale revisions, unknown or resolved call IDs, and cross-conversation decisions
fail closed.

`PrepareMealPlanRecipes` builds one structural request for every custom planned
meal without a recipe version. The authoritative input contains the accepted
meal, date, participants and servings, household/person preferences, explicit
constraints, other meals, and plan state. It also carries bounded,
source-linked conversation excerpts: selected-proposal sources, applicable
preference and constraint sources, and recent instructions about timing,
budget, variety, leftovers, nutrition, or ingredients. Chef does not run a
separate summarisation invocation.

The request, fingerprint, requester, and generation status are stored before
one `MaterializeMealPlanRecipesJob` is dispatched to the `ai` queue. The same
active fingerprint does not dispatch twice.

`MaterializeMealPlanRecipes` claims only pending work, validates exact-once
coverage for the whole request, rechecks the fingerprint under a transaction,
and creates all recipe versions atomically. It records one safe failure state
instead of exposing provider details. Retrying clears the failure and starts a
new pending attempt.

The readiness contract exposes:

- `prepare_recipes` when an approved plan has unresolved recipes but no active
  batch;
- `wait_for_recipes` while the batch is pending or processing;
- `retry_recipes` after failure;
- `recipes_ready` once every required recipe exists.

Changing a confirmed plan marks derived data stale. Recipe preparation resumes
only through explicit reapproval or the recovery endpoint; ordinary selection
actions do not start background work before approval.

`MealPlanRecipesReady` is dispatched after the recipe transaction commits.
`QueueGroceryPlanBuild` then starts the grocery pipeline; it cannot observe
half-materialised recipe data.

When an approved run has a standing grant, `ProbeRetailerConnectionJob` checks
authentication immediately while recipe preparation proceeds independently.
The probe, discovery, replacement, restoration, and owner review all share one
connection-level `WithoutOverlapping` lock. Expired authentication is preserved
as `reauthentication_required` through grocery-plan construction; transient
probe failures are retried only by later discovery. Reauthentication retains
the existing grant and never re-prompts for consent.

## Domain boundaries

### Tenancy

`Team` is the tenancy boundary and appears as a family or household in product
language. `User` and `Person` are separate: accounts authenticate, while people
participate in meals and own preferences or constraints. A user may belong to
several teams.

Every team-owned route uses current-team binding where applicable, every
mutation authorises its root record, and policy tests cover cross-team denial.

Household invitation links are temporary signed URLs over the invitation's
existing opaque token and expiry. A valid guest visit stores only the pending
invitation identifier and intended destination in the session; shared
authentication props expose the household name, inviter name, and destination
URL, never the invited email or participant history. Acceptance still requires
an authenticated matching email and runs through the same atomic team action.

### Planning

`MealPlan` supports arbitrary date ranges. `MealSlot` owns date, meal kind,
position, participants, and per-person servings. `MealProposal` is reviewable
intent; `PlannedMeal` is the selected durable result.

Plan revisions are append-only summaries of meaningful changes. Conversation
messages may explain or initiate changes but do not replace plan state.

### Household truth

Allergies and safety constraints are explicit records and are never inferred
from preference evidence. Preferences retain subject, owner, sentiment,
provenance, evidence, and correction history. Feedback-derived candidates
require review before promotion to an ordinary preference.

### Recipes and cooking

Canonical ingredients and recipe ingredients remain recipe-domain concepts.
Recipes own immutable versions containing ingredient snapshots, steps,
equipment, notices, timing, servings, notes, and storage guidance. Planned
meals retain the exact version used.

Cooking progress and outcomes are durable. Feedback belongs to individual
participants and can be edited without mutating historical recipe versions.
The web client persists the required rating immediately through the existing
meal-feedback endpoint and sends every currently saved optional field with that
request so a rating edit cannot clear detail. Portion, effort, cost, leftovers,
notes, and recipe adjustment are then submitted together from an optional
questionnaire; unfinished client drafts are session-local and are never durable
truth.

Direct safety-rule capture uses the existing constraint endpoint and
`RecordConstraint`. Its inline questionnaire requires explicit scope, kind,
subject, a review summary, and an initially unchecked confirmation. Only that
confirmed submission sends `explicitly_confirmed: true`; the separate current-
plan safety review remains a distinct action. Server validation and team-scoped
person lookup remain authoritative and fail closed.

### Grocery plans and product selection

`GroceryPlan` is a versioned derivative of an approved plan's retained recipe
versions. `BuildGroceryPlan` scales every non-optional recipe ingredient by the
planned servings, excludes water, combines only compatible normalised
forms/units, and retains a `GroceryRequirementSource` for every contributing
recipe ingredient. V1 assumes no pantry inventory. An unknown quantity remains
explicit and later selects the smallest otherwise-valid standard pack.

Each requirement carries a deterministic fingerprint, up to three progressively
broader search queries, and the applicable explicit constraints. A new plan
version supersedes rather than mutates earlier grocery truth.

`RetailerPurchasePolicy` stores the household/provider defaults for home brand,
bulk, organic, ordered preferred brands, and an optional basket target.
`MealPlanPurchasePreference` may override only the target. The effective policy
is snapshotted and fingerprinted into each grocery plan. Defaults are home brand
allowed, bulk avoided, no organic preference, no preferred brands, and no
target; they are edited through grocery settings or explicit conversation
language rather than onboarding.

`DiscoverRetailerProducts` asks the bounded retailer adapter only for Coles
pages. Captured candidates retain safe product data and label evidence, never
raw HTML or browser snapshots. Hard gates reject the wrong origin, missing or
invalid SKU, unavailable stock, unusable pack/price data, restricted products,
known safety conflicts, and insufficient label evidence for an applicable
explicit constraint.

An active `RetailerProductPreference` wins when its SKU was rediscovered and
still passes every gate. Otherwise one batched Laravel AI structured-output
ranker receives only captured candidates and returns ordered semantic tiers
containing every eligible candidate ID exactly once. Chef rejects missing,
duplicate, or unknown IDs. Within the highest tier, deterministic policy
preferences may choose combinations costing no more than 15% above the
cheapest equivalent option. `avoid` bulk prefers totals no greater than twice
the requirement where possible. Low semantic confidence may force the best
candidate only inside the already-valid set; no valid candidate blocks the
whole run before mutation.

Pack counts are deterministic. Chef chooses the least-waste valid combination
when it costs no more than 10% above the cheapest combination; otherwise it
chooses the lowest total price.

Discovery persists each query's sequence, deterministic/AI/fallback method,
result counts, safe reason code, and capture time. Two deterministic queries run
first. If requirements remain unresolved and the recovery feature flag is on,
one batched `RetailerSearchRecoveryAgent` may provide one non-URL query of at
most 80 characters for each existing unresolved requirement. Invalid output or
provider failure uses the deterministic broad query; the total never exceeds
three searches per requirement and all hard gates are reapplied unchanged.

The basket view exposes up to three still-valid captured alternatives for each
selected item. `RememberRetailerProductPreference` verifies requirement
membership and every hard gate before saving `Prefer next time`; it never
changes the current retailer basket. Durable policy or product preferences may
be saved from conversation only after explicit language such as `always`,
`prefer`, or `next time`.

### Budget and unavailable-product recovery

Selection stops before mutation when the Chef subtotal exceeds the effective
target or no candidate survives bounded discovery. One idempotent
`MealPlanAdjustmentDraft` may be prepared per approved plan version and recovery
kind. Its items may reference only existing plans, slots, meals, and
requirements; unavailable-product drafts must cover every blocked requirement.
The resulting pending proposals leave the approved plan unchanged and move the
run through `preparing_resolution` to `needs_plan_review`.

For budget overruns, the Coles account owner may either review the coherent
cheaper-plan diff or record a run-specific `Use this basket` override. An
override permits preparation only, supersedes the unused proposals, revalidates
products, and does not authorise checkout or spending. Reapproval accepts the
displayed proposals atomically and starts a fresh recipe/grocery run. A second
failure of the same recovery kind stops for manual attention rather than
creating an agent loop.

### Connections, consent, and basket mutation

`RetailerConnection` is household-scoped and owned by the Coles account owner.
Its Browserbase Context ID and active session ID are encrypted and hidden.
`RetailerAutomationGrant` records the owner, exact disclosure version/hash,
scope, grant time, and revocation. Revoking consent pauses affected runs;
disconnecting also requests deletion of the hosted Browserbase Context.

Authentication uses an owner-only, short-lived Browserbase Live View. Chef
stores no password, Live View URL, raw HTML, accessibility snapshot,
screenshot, or recording. Browserbase session recording and logging are
disabled. The Live View URL is returned once as an authorised capability and
is not written to the database.

The native client embeds Live View in a non-persistent `WKWebView`. Because
Browserbase does not officially support mobile Live View keyboards, an
owner-only `/api/v1/retailer-connections/{connection}/live-input` endpoint
accepts either at most 256 characters or one allowlisted navigation key while
an unexpired authentication/review session is active. The value is marked
sensitive, passed once to Stagehand `page.type()`/`page.keyPress()`, never
persisted or echoed, and cleared by the client. Closing the native or web view
calls the explicit live-session release endpoint. Real-device security and
keyboard feasibility remain release evidence, not an inferred guarantee.

`ReplaceBasket` revalidates every selected product, captures a structured
baseline, and executes `ensure_basket_empty` followed by
`ensure_basket_line(product_id, absolute_quantity)`. Each mutation includes the
checksum from the immediately preceding inspection. A lost response triggers
inspection before retry. A concurrent edit or unverifiable response produces
`uncertain` and stops automation.

If replacement fails after clearing, Chef automatically attempts to restore
the baseline. Incomplete restoration becomes `needs_attention`. A verified
success stores selected products, absolute quantities, line prices, Chef
subtotal, full Coles basket total, capture time, and checksums. Price copy
always states that values remain time-sensitive until checkout.

`BasketRun`, its items, and baseline/final/restoration snapshot records are
Chef's durable evidence. They must not be confused with Stagehand
`page.snapshot()`, which is transient accessibility-tree input only.

## Conversation and AI

The Laravel AI SDK is wrapped by Chef-owned contracts. `ChefAgent` receives
authorised household, plan, recipe, and conversation context and exposes narrow
tools backed by the same domain actions used by HTTP controllers.

The conversational agent may inspect and change planning, household, and recipe
state within the user's authority. It has no basket-mutation, fulfilment, or
order tools. Product ambiguity is handled by the separate bounded ranker after
deterministic discovery and hard validation; the browser worker never delegates
the workflow to Stagehand `agent()`.

Provider output is untrusted. Structured recipe output is validated before any
durable recipe is written. Normal tests use SDK fakes or a deterministic
`MealPlanRecipeDrafter`; they never call live providers.

Chef conversations atomically link one SDK-private `agent_conversations` ledger
immediately before their first provider call. Later turns reuse that unique
ledger even when multiple clients begin from stale conversation snapshots.
The linked ledger stores `agent_conversation_messages` execution history.
Pre-link Chef messages are supplied once through a stored cutoff; later tool
calls, approval state, and tool results are rehydrated from the SDK ledger.
SDK title generation is disabled, and deleting a meal-plan conversation also
deletes its linked ledger. Chef's `conversations` and `messages` remain the
visible product transcript, while household, plan, safety, and consent truth
remain in Chef-owned domain records.

Conversation recovery derives acknowledgements from durable plan revisions,
preference changes, proposals, and recipe readiness. A provider failure cannot
invent a completed mutation.

User planning messages may include up to four private JPEG, PNG, WebP, HEIC, or
HEIF photos of 10 MiB each. `CreateUserMessage` uses Laravel's image pipeline to
auto-orient each image, scale its longest edge down to at most 2560 px without
upscaling, strip metadata and the source filename through re-encoding, optimize
it as an 82-quality JPEG, store it privately, and atomically record ordered
public dimensions and size in `message_attachments`. JPEG, PNG, and WebP inputs
use the default GD driver. HEIC and HEIF inputs require an ImageMagick build
with `libheif`; Imagick decodes, orients, and scales them to lossless PNG pixels
before GD performs the canonical metadata-free JPEG encoding. Missing codec
support fails as field validation rather than storing the undecoded source. The
disk, path, and source hash remain server-only. Existing WebP attachments remain
readable and are not reprocessed.
`client_message_id` retries reuse the original files; a reused identifier with
different text or image hashes is rejected.

Both web and `/api/v1` conversation stream endpoints retain their JSON contract
and also accept multipart `content`, `client_message_id`, and `images[]`.
Additively, a text-free turn may contain
`approval: { id, decision: "approve" | "reject" }`; the server never accepts a
client-supplied tool name, arguments, or arbitrary result. The NDJSON stream may
emit `tool_approval_request` with a safe `ConfirmPlan` projection containing
only its call ID, bound plan revision, and reason. The latest unresolved
projection is included in `BuildMealPlanWorkspace` and is invalidated by a
later user turn, revision change, resolution, or confirmed plan.
Photo-only turns keep an empty transcript body and use neutral internal prompt
copy. Current and earlier user photos are passed to the Laravel AI SDK as image
attachments. Visual observations may inform qualified suggestions, but photos
never establish allergies, safety constraints, medical restrictions, or
durable preferences.

Apple WebKit confirms and previews HEIC and HEIF candidates through its native,
hardware-accelerated rendering. Other browsers lazily load the unmodified CSP
build of `heic-to` to confirm the file content and sequentially prepare a
temporary 75-quality JPEG preview. Both paths retain the original file for the
multipart upload. Preview failure remains visible but does not replace server
validation. Temporary object URLs are revoked after removal, replacement by
authoritative workspace data, or unmount.

Authenticated web and Sanctum routes stream attachments from private storage
after household and parent-conversation authorisation. Cross-household lookups
resolve as `404`; responses are inline, privately cached, and marked `nosniff`.
Deleting the meal-plan conversation atomically records each private file in a
cleanup outbox before removing database ownership. A queued cleanup runs after
commit, and the scheduler retries retained outbox rows until storage confirms
deletion.

## Testing architecture and release evidence

The ordinary Pest boundary discovers only `tests/Unit` and `tests/Feature`.
Every ordinary test freezes the application clock, prevents stray Laravel HTTP
requests, and installs fail-closed Laravel AI SDK fakes for Chef, recipe
drafting, plan adjustment, product ranking, and search recovery. Browser,
Integration, and live Eval suites are selected explicitly so a fast or full
backend run cannot accidentally start a browser, wait for infrastructure, or
spend provider tokens.

Tests in the `happy-path` browser group are the required web product contract.
They use real routes, actions, jobs, policies, and persisted facts while faking
only the AI/provider boundary. The group covers registration through planning,
approval, recipe materialisation, Calendar/List, cooking, outcome and feedback;
first-run Coles Live View and explicit standing consent; and standing-consent
discovery, replacement, review, alternatives, and future preference. Each
journey asserts desktop and iPhone-sized behaviour, accessibility, and browser
smoke. CI runs the complete browser suite in Chromium and repeats the required
group in Safari/WebKit.

The retailer integration suite deliberately does not use `RefreshDatabase`.
Its test process commits plan and connection state to PostgreSQL, dispatches to
Redis, and polls facts written by a separately started `queue:work` process.
The deterministic recorded gateway fixture resolves the same worker pipeline
without Browserbase, Coles, Stagehand, or provider network calls. A local run
skips unless PostgreSQL, Redis cache, Redis queue, the fixture, and the external
worker have all been configured.

CI enforces 80% application coverage and an 80% mutation floor over the three
highest-consequence actions: plan approval, retailer product selection, and
basket replacement. Pest 5.0.2 currently writes PHPUnit 13 coverage as reduced,
serialized data while its bundled mutation runner expects the older object and
absolute paths. `scripts/prepare-pest-mutation-runtime.php` applies one
checksum-pinned compatibility preparation before mutation runs and fails closed
when the locked upstream source changes.

Deterministic Unit coverage verifies that authoritative identifiers, explicit
safety constraints, and output boundaries are baked into all four structured
agent prompts. The separate live Eval suite judges those workloads and Chef's
tool/safety behaviour. Its manual and weekly workflow requires an OpenAI key
and fails rather than reporting a false pass when credentials are absent. Live
provider quality is evidence, not a deterministic pull-request gate.

The CI workflow exposes one `Required test gate` aggregate over backend,
frontend, retailer infrastructure, browser, mutation, and iOS jobs. GitHub
branch rules must require that aggregate for the workflow to prevent a merge;
the repository cannot infer or silently alter that host-level setting. As
checked on 2026-08-01, GitHub returned `403` for `main` protection because this
is a private repository on a plan without protected-branch support. A GitHub
plan upgrade or making the repository public is therefore required before the
aggregate can become an actual host-enforced merge block.

## HTTP and Inertia

The primary authenticated surfaces are:

- dashboard / Today;
- meal-plan conversation, Calendar, and List;
- recipes and recipe versions;
- calm grocery-preparation progress and a dedicated basket review page;
- cooking mode, outcomes, and feedback;
- household profile and security settings.

`BuildMealPlanWorkspace` supplies planning, household, conversation, recipe
readiness, and a compact `grocery_preparation` projection. Basket details use a
dedicated authorised loader. In-app database notifications are the only
notification channel.

`ProjectBasketRunPublicState` maps internal orchestration to the stable public
states `preparing`, `connection_required`, `plan_review_required`, `ready`,
`needs_attention`, and `failed`, with a contextual public outcome. Routine
internal phases collapse into one calm card and appear only in an optional
details disclosure. Notifications are emitted only for ready or actionable
states. `BasketRunStatusTransition` stores safe state codes and durations, and
the worker reports a bounded Stagehand fallback count without persisting browser
evidence or model reasoning.

Historical shopping/order URLs intentionally have no redirects and still
return `404`.

## Native iOS and mobile API

The native iOS application lives in `ios/` inside the Chef repository. It is a
second client of the Laravel monolith, not a separate domain service. SwiftUI
owns native presentation and transient interaction state; Laravel remains
authoritative for household membership, safety, planning revisions, proposal
state, readiness, approval, recipes, and conversation persistence.

The versioned `/api/v1` contract uses Laravel Sanctum bearer tokens with a
30-day expiry and explicit per-device revocation. Tokens are stored by the
native client in the iOS Keychain. Mobile password authentication honours
Fortify two-factor authentication, including recovery-code rotation; it must
not issue a token that bypasses an enabled second factor.

`BuildMealPlanWorkspace` is the shared server-side workspace loader for Inertia
and JSON responses. Native endpoints remain thin adapters around the same
actions used by web controllers and AI tools. The first contract covers:

- registration, token issue, current-token revocation, and session bootstrap;
- first-plan creation and the complete planning workspace;
- newline-delimited conversation streaming with `client_message_id`
  idempotency, including optional private image attachments;
- participant changes with optimistic revision checks;
- proposal acceptance and rejection;
- explicit safety review and whole-plan approval;
- retailer connection creation, consent verification, live input/release, and
  disconnection;
- grocery progress, basket retrieval, retry, restoration, owner review, and
  notification acknowledgement.

The mobile API exposes the existing `application/x-ndjson` conversation stream,
but the current Swift target does not yet implement the native riffing surface.
When it does, streamed text will remain optimistic presentation state only and
the refreshed workspace will remain the source of truth. A revision conflict
must reload and explain the newer plan rather than applying a stale local
mutation.

The current SwiftUI target stores its API token in Keychain and provides
sign-in/TOTP, plan selection and approval, foreground grocery polling, Coles
connection/consent, basket review, retry/restoration, owner handoff, and the
ephemeral input relay. It does not queue offline mutations, poll in the
background, or register for push. Native conversation, Calendar/List editing,
voice, passkeys, and a complete first-plan journey remain later work and are
not described as finished.

`ChefAPI` decodes approval briefs, effective policy and fingerprint, plan
targets, coherent adjustment drafts, semantic tiers, policy decisions and
exceptions, alternatives, and saved product preferences additively. Optional
fields and unknown statuses remain safely decodable during staged deployment.
The SwiftUI app uses these contracts for grocery settings, plan-target editing,
authoritative approval review, `Prefer next time`, revocation, and coherent
owner/non-owner recovery. Every mutation suppresses duplicate submission and
refreshes authoritative server state. Debug-only, network-free XCUITest
fixtures exercise the M5.1 interaction path; they are never compiled into a
release build and do not replace the real-iPhone or authenticated Coles gates.

## Frontend state

Conversation remains the default plan view. Calendar and List are direct
manipulation views over the same server-authoritative plan. The composer stays
available while recipe preparation is pending or failed.

Chef vendors the shadcn Radix Questionnaire wrapper as app-owned UI code backed
by `@shadcn/react/questionnaire`. The wrapper owns presentation, focus, progress,
keyboard navigation, and error composition; Inertia forms continue to own
payloads, processing state, server errors, and durable submission. Questionnaire
use is limited to post-cooking detail and explicit safety-rule capture.

Web and native clients poll only while a recipe/basket run is active and only
while Chef is foregrounded. A terminal transition refreshes the durable basket
and in-app notifications. Server-authoritative rules are not duplicated in
TypeScript or Swift.

## Queues and operations

The application uses `default`, `ai`, and `retailer` queues. Recipe jobs are
unique per plan and retain an application-level failure state suitable for an
explicit retry. Requirement building, discovery, selection, replacement, and
restoration are separate jobs. Retailer jobs have application claims and
unique run locks; consequential mutation has one queue attempt and is
reconciled by inspection rather than blind retry. Database, Beanstalkd, and
Redis queue leases default to 360 seconds, longer than the maximum 300-second
retailer job timeout, and deployments must preserve that ordering. Discovery
and selection record a safe terminal failure after their final attempt instead
of leaving a run active indefinitely.

Live mutation is refused unless the experience, discovery, and mutation flags
are enabled, the deployment master switch permits mutation, and the no-expiry
Redis runtime breaker is explicitly closed. Missing, malformed, or unreadable
runtime state fails closed. The breaker is checked before a new replacement or
restoration claim; opening it does not abandon verification/restoration after
an in-flight replacement has already cleared the original basket. Production
rollout also requires PostgreSQL and Redis; SQLite remains supported for
non-live local development and tests. A read-only discovery run terminates as
`products_selected`, explicitly records that the basket was unchanged, and
does not poll indefinitely or masquerade as a verified basket.

The Node worker implements `chef.retailer.v1`. Its six typed commands are
`probe_auth`, `search_products`, `inspect_basket`, `ensure_basket_empty`,
`ensure_basket_line`, and `release_session`. Results are discriminated as
`succeeded`, `blocked`, `retryable`, `uncertain`, or `failed`, with safe reason
codes and verification checksums. Context creation/deletion, short-lived Live
View creation, and the ephemeral mobile input relay are lifecycle operations,
not additional basket commands.

Only one session may own a retailer Context. Sessions use the Australian proxy
country and `ap-southeast-1`, restrict navigation to Coles domains, and disable
Browserbase recording/logging. Live View claims, retries, and restoration use a
connection-first database lock and recheck both active sessions and active runs
inside that lock. Deterministic locators run first; bounded
Stagehand `observe` and validated `act` may recover moved controls.

`npm run build` compiles both the Inertia application and the worker; CI also
runs all six worker protocol fixtures against the development and pruned
production installs. CI requires zero Composer advisories and zero moderate,
high, or critical advisories in the deployed Node graph. The current pruned
graph reports zero advisories. Stagehand 3.7.1 imports optional providers from
its normal entrypoint, so Chef checksum-pins a small production preparation
step that removes those unused imports after pruning while retaining its
required OpenAI adapter and `chrome-launcher`. A Stagehand upgrade must review
that checksum before it can deploy. Chef does not use Stagehand `agent()`,
bounds input and fallback work, and retains the mutation breaker.

`retailer:readiness --json` verifies PostgreSQL, Redis cache, a harmless Redis
`retailer` queue marker, the built `chef.retailer.v1` artifact, feature flags,
and breaker state without external network access. Operators use
`retailer:circuit-breaker status|open|close --reason=...`; closing requires the
master switch and a successful readiness probe. `retailer:metrics` aggregates
safe transition outcomes, durations, restoration results, and bounded
Stagehand fallback counts without team, user, product, credential, or browser
evidence.

## Data removal

`2026_07_23_140000_remove_shopping_and_retailer_stack.php` remains the forward
cleanup migration for the discarded shopping/order design. It removes those
obsolete tables child-first,
removes obsolete meal-plan approval columns and milestones, and clears queued
or failed payloads for deleted job classes.

Historical migrations remain byte-for-byte unchanged so deployed and fresh
databases execute the same history. The two legacy shopping enums imported by
those migrations remain available only as frozen migration dependencies; the
current runtime does not use them. Rolling the cleanup migration back recreates
the obsolete schema empty; deleted records are not recoverable.

`2026_07_31_153454_create_plan_to_basket_beta_tables.php` introduces the new
provider-neutral connection, consent, grocery, candidate, selection, preference,
basket-run, and snapshot records. It does not revive removed route or model
contracts.

## Testing contract

Every behavioural change should cover:

- the action or invariant directly;
- cross-team authorisation for team-owned state;
- idempotency for retryable or externally visible work;
- atomic recipe materialisation and safe retry;
- grocery aggregation, hard product gates, pack optimisation, and invalid
  ranker output;
- consent ownership, cross-team isolation, idempotent replacement, lost
  responses, concurrent edits, and restoration;
- worker protocol fixtures with normal-test network prevention;
- Inertia payload and route expectations where contracts change;
- desktop and narrow-screen workflow behaviour for UI changes.

The local backend gate is `composer test`: formatting and PHPStan level 7 over
application and test code, followed by Pest 5 Test Impact Analysis. TIA runs
with PCOV and keeps its dependency graph in Pest's user cache.
`composer test:tia:fresh` rebuilds that graph. TIA is an acceleration layer
only; `composer test:full`, coverage, and CI pass `--ci --no-tia` and execute
the complete deterministic suite. A direct `vendor/bin/pest` invocation also
runs the full suite without TIA; only the project-aware Composer launcher can
reconcile the nested `web/` application with the repository Git root.

The local browser command is `composer test:browser`; the CI-mode full browser
gate is `composer test:browser:ci`. Pest Browser uses Playwright strictly as
development/test infrastructure; it is not part of the application or retailer
automation.

Pest Agent provides one-off full-stack probes under the same global Laravel
`TestCase` and `RefreshDatabase` setup as functional tests. A probe is
exploratory evidence, not durable coverage, and must become a committed test
when it protects an invariant or workflow.

`tests/Evals` is an explicitly live, advisory suite for Chef's safety,
household-truth, planning, tool-boundary, recipe, and prompt-injection
behaviour. Its Chef-owned harness serialises Laravel AI response text, tool
calls, and tool results so Pest can combine deterministic trajectory checks
with narrow LLM-judge criteria. `composer test:evals` requires
`OPENAI_API_KEY`; normal test commands skip evals before fixture construction
and never call a provider. The manual/weekly workflow is intentionally outside
pull-request gating.

Laravel Boost's portable configuration is `web/boost.json` and
`web/config/boost.php`. Generated guidelines are tagged inside the existing
root `AGENTS.md`, generated skills are shared at root `.agents/skills`, and
root `.codex/config.toml` starts `php artisan boost:mcp` with `cwd = "web"`.
MCP writes require approval. Tinker and rule-writing are disabled; read-only
application information, documentation search, route, log, schema, and
database-query tools remain available. The repository must be trusted and
Codex restarted before a new task can load a changed project-local MCP. The
Composer post-update hook refreshes generated guidance and skills.

Frontend handoff also requires ESLint, Prettier, TypeScript, the production
build, worker tests, and React Doctor. Native handoff requires `swift test`, a
simulator build/test, Dynamic Type and VoiceOver review, and a real-device
authentication/relay journey. Live Coles pilot cases never run in ordinary CI.
