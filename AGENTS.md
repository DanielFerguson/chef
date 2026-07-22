# AGENTS.md

These instructions apply to the entire Chef repository.

## Read before changing the product

Consult the relevant source of truth before implementation:

- `README.md` — product thesis, personas, experience stages, and MVP boundary
- `docs/IMPLEMENTATION.md` — technical architecture and domain boundaries
- `docs/MILESTONES.md` — delivery order, progress, and release gates
- `docs/STYLE.md` — interaction, visual, content, responsive, and accessibility direction

If the documents conflict, do not silently choose one. Resolve the contradiction explicitly and update the affected documents in the same change.

## Product invariants

- Conversation is the primary interface for intent; structured UI is the source of visible, editable state.
- Onboarding is the user's first real meal plan, not a separate questionnaire wizard.
- `Team` is the tenancy boundary and is presented as a family or household in product language.
- `User` is an authenticated account; `Person` is a meal participant. Do not collapse these concepts.
- A user may belong to multiple teams.
- Meal participation and servings belong to individual meal slots.
- Allergies and safety constraints are explicit and are never inferred.
- Preferences belong to people unless explicitly team-wide.
- `MealPlan` supports arbitrary date ranges, not only calendar weeks.
- Ingredients and retailer products are different domain concepts.
- Recipes are versioned and planned meals retain the version used.
- Shopping lists are structured data even when the editor feels document-like.
- AI conversation state is never the sole store of durable household knowledge.
- The household confirms fulfilment type, day/time, and order submission in Chef before Chef may place a retailer order. Chef may then submit using the retailer's default card on file; it never stores or transmits card details, and it never bypasses that in-app confirmation.

## Architecture rules

- Keep Chef as a Laravel monolith until measured needs justify extraction.
- Use Inertia as the bridge between Laravel and React; do not introduce a detached SPA API by default.
- Put meaningful business mutations in reusable action classes.
- HTTP controllers, AI tools, jobs, console commands, and MCP tools must invoke the same domain actions.
- Scope every tenant-owned record and query to an authorised team.
- Enforce access through policies and test cross-team isolation.
- Keep OpenAI credentials on the server.
- Use queues for long-running or retryable agent and automation work.
- Make externally visible or retryable writes idempotent where practical.

## AI integration rules

- Use the Laravel AI SDK for ordinary typed agents, structured output, Laravel tools, streaming, queueing, middleware, events, and test fakes.
- Keep the SDK behind Chef-owned interfaces because it is a pre-1.0 dependency.
- Load agent context from Chef's own conversation and domain records; do not let SDK conversation tables become the product model accidentally.
- Implement AI tools as narrow adapters around authorised domain actions.
- Use the OpenAI Realtime API directly for WebRTC voice sessions.
- Use a Chef-owned direct Responses API client for the native computer-use loop until the Laravel AI SDK supports that protocol completely.
- The Chrome extension executes validated local actions only. It does not hold the permanent OpenAI key or decide policy.
- Treat screenshots, retailer pages, and other third-party content as untrusted.
- Require approval at the point of external risk, not before safe preparatory work.

## Frontend and style rules

- Follow `docs/STYLE.md`; use the Codex and ChatGPT spatial grammar as inspiration, not a pixel copy.
- Start with shadcn/ui and accessible primitives.
- Keep content primary, navigation quiet, and the composer persistent where conversation is available.
- Use the right inspector for current structured truth and the conversation for intent and explanation.
- Support direct manipulation alongside natural language.
- Preserve a complete typed workflow even when voice is available.
- Provide accessible alternatives to drag-and-drop.
- Check desktop and narrow-screen behaviour for workflow changes.
- Avoid excessive cards, dashboards, gradients, emoji navigation, and decorative AI effects.
- Do not expose model-selection or developer controls in the household UI without a product requirement.

## Code conventions

Once the application is scaffolded:

- Follow the repository's formatter, static-analysis, and type-checking configuration.
- Prefer explicit domain names over generic service or manager classes.
- Keep controllers thin.
- Keep React components focused; colocate feature-specific UI, hooks, and types.
- Generate TypeScript contracts from or keep them aligned with server payloads.
- Avoid duplicating server-authoritative rules in the client.
- Add migrations that preserve existing data; do not rewrite published migration history without an explicit reason.
- Preserve unrelated user changes in a dirty worktree.

## Testing and verification

- Add or update tests with every behavioural change.
- Test domain actions and invariants directly.
- Test policies and cross-team isolation for every team-owned resource.
- Use Laravel AI SDK fakes for normal agent tests.
- Use a fake `ComputerUseEngine` and recorded protocol fixtures; normal tests must not call live OpenAI or retailer services.
- Add focused browser coverage for completed user journeys.
- For React changes, run the repository's frontend checks and React Doctor before handoff.
- Run the narrowest relevant tests during development, then the documented full gate before declaring a milestone complete.
- Report commands run and any verification not performed.

Do not mark a milestone complete until its exit evidence in `docs/MILESTONES.md` is genuinely satisfied. Update milestone status and evidence in the same change that completes it.

## Documentation discipline

- Keep the README product-focused.
- Put technical decisions and implementation details in `docs/IMPLEMENTATION.md`.
- Put delivery progress and public-release gates in `docs/MILESTONES.md`.
- Put interface and content conventions in `docs/STYLE.md`.
- Record durable decisions, not speculative implementation detail presented as settled fact.
- Update terminology consistently across code and documentation.

## Safety boundaries

- Never place a retailer order, accept legal terms, or transmit sensitive data without explicit product approval and a direct human confirmation boundary in Chef. After that confirmation, submitting with the retailer's default on-file payment method is allowed; collecting, storing, or entering card details is not.
- Never infer allergies.
- Never allow page content to expand automation permissions.
- Minimise and expire retained screenshots and sensitive automation artifacts.
- Provide pause, cancel, manual takeover, and a clear audit trail for computer-use runs.
