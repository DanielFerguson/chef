# M8 verification record

Recorded 17 July 2026 for the M8 code-level release candidate. These results do
not claim that the pending production, retailer-account, microphone, restore,
or private-beta gates ran.

## Automated gates

| Gate | Result |
| --- | --- |
| `composer test` | Passed: 257 tests, 1,464 assertions; Pint and PHPStan clean. |
| `composer test:coverage` | Passed: 257 tests, 1,464 assertions; 86.8% application coverage (minimum 75%). |
| `composer test:browser` | Passed: 29 browser tests, 243 assertions across desktop and 390×844 journeys. |
| Frontend format, ESLint, TypeScript, production build | Passed. |
| React Doctor | 100/100, no issues in changed React code. |
| Chrome extension `npm run check` | Passed: syntax checks and 8 policy/release-config tests. |
| Production extension build assertion | Passed for a non-production verification origin: exact API destination, host permissions, clean six-file package, and version emitted with no localhost/example permission. This was not a distributable beta package. |
| Marketing production gate | Passed: 8 configuration acceptance/rejection tests, frozen Bun install, Astro with 0 diagnostics, safe static checks, and an exact non-placeholder `/start` and `/login` contract. |
| `composer audit --locked` | No security vulnerability advisories. |
| `npm audit --audit-level=high` | 0 vulnerabilities. |
| `bun audit --audit-level=high` | No vulnerabilities. |
| Release runtime probe | Passed in focused tests for database, cache, object-storage cleanup, queue round-trip, and scheduler freshness; negative tests reject a non-processing queue, stale scheduler, unavailable cache, and unavailable storage adapter without crashing. A live local database-queue worker also returned all five component probes as `true`; the real Redis/S3 deployment result remains pending. |

The CI workflow now requires backend coverage and Composer audit, MySQL 8.4
portability, frontend build and npm audit, strict marketing build and Bun audit,
extension policy and production packaging, and the browser suite as independent
jobs. A hosted run of these jobs is still part of the production-like replay
gate rather than evidence from this local verification.

The final approval suite proves a complete signed manifest can approve the exact
configured deployment and rejects waivers, weak retailer/beta results, stale or
old approval evidence, arbitrary content, strict-type violations, query-string
secrets, wrong release/origin, signature tampering, restore RPO over 24 hours,
restore time over four hours, automation failure above 20%, and observed AI
cost above its configured family ceiling. The example manifest was executed and
correctly refused signing while pending.

The public `/start` handoff now routes guests to registration and authenticated
households to the dashboard. The full gate also exposed a midnight-only M3 test
flake caused by recalculating `today()` while constructing a fourteen-day plan;
the test now derives every slot from the persisted plan start date.

The release runtime probe now emits component-level JSON, dispatches a unique
no-risk job through the configured `default` queue, and requires the scheduled
shared-cache heartbeat to be no more than three minutes old. Final approval
re-runs these checks, so signed evidence cannot conceal a stopped worker or
scheduler. The focused sync-queue coverage and an actual local database-worker
round-trip prove the protocol; staging and production must still prove it
through Redis worker compute and private S3-compatible storage.

## Accessibility and responsive evidence

The complete browser suite covers onboarding/first-plan, planning and accessible
move alternatives, recipes, shopping, retailer approval/takeover, cooking and
feedback, sidebar/account controls, failure recovery, and typed voice fallback.
Focused 390×844 tests assert no horizontal overflow for the planning, recipe,
shopping, automation, cooking, settings, public information, and navigation
surfaces.

A manual in-app browser pass at 1280×720 and 390×844 covered `/`, `/privacy`,
`/terms`, `/security-and-privacy`, `/help`, and `/release-notes`. It verified one
`main` landmark, one visible `h1`, named links/buttons, valid
`aria-labelledby` references, and no horizontal overflow. The pass found and
closed nested landmarks, an empty `mailto:null` link, undersized navigation tap
targets, and missing IDs on Data and privacy section headings. See
`docs/accessibility/M8-ACCESSIBILITY-REVIEW.md`.

## AI and failure evidence

- Rate limiting rejects a request before a user message is created.
- Monthly token/cost limits and daily automation/voice limits fail with a
  usable fallback.
- Interrupted and failed streamed turns persist a retryable state and do not
  duplicate a response.
- Recipe generation records a content-safe failure and can retry without
  duplicate recipes or revisions.
- Automation tests cover pause, cancellation, lease expiry, foreign tokens,
  invalid origins, failed extension actions, approvals, reconciliation, and
  takeover before prohibited controls.
- Production 403/404/429/500/503 pages provide retry, back, and help paths; a
  419 response preserves the session-expiry recovery path.

## Remaining evidence

The unchecked items in `docs/M8-LAUNCH.md` are authoritative: staging and
production probes, backup restore, final legal/operator/support values, exact
provider prices, production extension/CSP/monitoring proof, signed-in
Woolworths and Coles, real microphone and Realtime, private family beta,
production-like replay, and the version 1 tag/publish step.
