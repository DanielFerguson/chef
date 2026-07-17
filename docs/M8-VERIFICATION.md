# M8 verification record

Recorded 17 July 2026 for the M8 code-level release candidate. These results do
not claim that the pending production, retailer-account, microphone, restore,
or private-beta gates ran.

## Automated gates

| Gate | Result |
| --- | --- |
| `composer test` | Passed: 239 tests, 1,396 assertions; Pint and PHPStan clean. |
| `composer test:coverage` | Passed: 239 tests, 1,396 assertions; 86.5% application coverage (minimum 75%). |
| `composer test:browser` | Passed: 29 browser tests, 243 assertions across desktop and 390×844 journeys. |
| Frontend format, ESLint, TypeScript, production build | Passed. |
| React Doctor | 100/100, no issues in changed React code. |
| Chrome extension `npm run check` | Passed: syntax checks and 6 policy tests. |
| Production extension build assertion | Passed for a non-production verification origin: exact host and version emitted, with no localhost/example permission. This was not a distributable beta package. |
| `composer audit --locked` | No security vulnerability advisories. |
| `npm audit --audit-level=high` | 0 vulnerabilities. |

The CI workflow also contains a MySQL 8.4 migration/test job. Its hosted run is
still part of the production-like replay gate rather than evidence from this
local verification.

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
