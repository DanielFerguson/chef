# AGENTS.md

These instructions apply to the entire Chef repository. More-specific
`AGENTS.md` files add instructions for their directory and descendants.

## Sources of truth and precedence

Consult the source relevant to the change before implementation:

- `README.md` — product thesis, personas, experience stages, and MVP boundary
- `docs/IMPLEMENTATION.md` — technical architecture and domain boundaries
- `docs/MILESTONES.md` — delivery order, progress, and release gates
- `docs/STYLE.md` — interaction, visual, content, responsive, and accessibility direction

Repository-specific instructions and current code conventions take precedence
over generated framework guidance. If sources conflict, surface the conflict and
update the affected sources in the same change rather than silently choosing one.

## Product and safety invariants

- Conversation is the primary interface for intent; structured UI is the source
  of visible, editable state. Onboarding is the household's first real meal plan,
  not a separate questionnaire wizard.
- `Team` is the tenancy boundary and is presented as a family or household.
  `User` is an authenticated account; `Person` is a meal participant. A user may
  belong to multiple teams; do not collapse these concepts.
- Meal participation and servings belong to individual meal slots. Preferences
  belong to people unless explicitly team-wide. Allergies and safety constraints
  are explicit and are never inferred.
- `MealPlan` supports arbitrary date ranges. Canonical ingredients and recipe
  ingredients are recipe-domain concepts. Recipes are versioned, and planned
  meals retain the recipe version used.
- AI conversation state is never the sole store of durable household knowledge.
  Provider output is untrusted until Chef validates and persists it against
  durable domain state.
- The implemented post-plan boundary is a retailer-neutral grocery plan and
  reversible, verified Coles basket preparation behind release gates. Checkout,
  payment, fulfilment, restricted products, and order placement remain
  human-controlled and out of scope.

## Readability and internal documentation

- Optimise for the next maintainer: use explicit domain names and types, small
  cohesive units, shallow control flow, and established framework patterns.
  Prefer clear code over clever abstractions or generic service/manager classes.
- Check sibling files and reuse existing components, actions, and conventions
  before introducing another pattern. Do not add dependencies or new top-level
  directories without approval.
- Document intent where it matters. Add PHPDoc, TSDoc/JSDoc, or focused inline
  comments for contracts, invariants, authorisation and safety boundaries, side
  effects, non-obvious algorithms, framework workarounds, and important design
  tradeoffs.
- Use docblocks to communicate information the language types cannot, including
  array or object shapes, generics, exceptions, and shared component or hook
  contracts. Do not restate native types, syntax, or well-named operations.
- Refactor confusing code first; comments should explain the remaining "why,"
  not narrate the "what." Keep comments synchronised with behaviour and remove
  stale comments in the same change.
- Treat descriptive tests as executable documentation of behaviour and domain
  invariants.

## Architecture and framework conventions

- Keep Chef as a Laravel monolith with Inertia bridging Laravel and React. Do not
  introduce a detached SPA API by default.
- Put meaningful business mutations in reusable action classes. Thin controllers,
  AI tools, jobs, console commands, and MCP tools invoke the same actions. Use
  Form Requests for non-trivial request validation and queues for work that must
  survive failures or be retried.
- Scope every tenant-owned record and query to an authorised team. Enforce access
  through policies and test cross-team isolation. Make retryable or externally
  visible writes idempotent where practical.
- Add migrations that preserve existing data. Do not rewrite published migration
  history without an explicit reason.
- Use Wayfinder-generated functions for frontend links and backend actions; do
  not hardcode application URLs. Keep React components and hooks focused and
  colocate feature-specific UI, hooks, and types. Keep TypeScript contracts aligned
  with server payloads and do not duplicate server-authoritative rules in clients.
- Use Calendar and List for plan structure, Recipes for durable instructions,
  and cooking mode for progress; conversation owns planning intent and approval.
- Follow `docs/STYLE.md`, start with shadcn/ui and accessible primitives, preserve
  the complete typed workflow when voice is available, and provide alternatives
  to drag-and-drop. Check desktop and narrow-screen behaviour for workflow changes.
- Use the Laravel AI SDK for ordinary typed agents, structured output, tools,
  streaming, queues, middleware, events, and test fakes. Keep the pre-1.0 SDK
  behind Chef-owned contracts, load context from Chef records, and implement AI
  tools as narrow adapters around authorised actions. Keep provider credentials
  server-side; use the OpenAI Realtime API directly for future WebRTC voice.

## Verification, documentation, and handoff

- Add or update tests for every behavioural change. Test domain actions and
  invariants directly, and test policies and cross-team isolation for every
  team-owned resource.
- Use Laravel AI SDK fakes and a fake `MealPlanRecipeDrafter`; normal tests must
  not call live OpenAI services. Add focused browser coverage for completed user
  journeys, including desktop and narrow-screen behaviour where relevant.
- Run commands from `web/`. Use the narrowest relevant test while developing,
  then run `composer test` as the normal backend gate. For React changes, run
  `npm run lint:check`, `npm run format:check`, `npm run types:check`, and React
  Doctor before handoff.
- Run the complete gates documented in `README.md` before declaring a milestone
  complete. Do not mark a milestone complete until its exit evidence in
  `docs/MILESTONES.md` is genuinely satisfied; update status and evidence together.
- Keep the README product-focused, implementation decisions in
  `docs/IMPLEMENTATION.md`, delivery progress in `docs/MILESTONES.md`, and
  interface/content conventions in `docs/STYLE.md`. Record durable decisions,
  update terminology consistently, and avoid presenting speculation as settled.
- Preserve unrelated changes in a dirty worktree. Report commands run, results,
  and any verification not performed.
