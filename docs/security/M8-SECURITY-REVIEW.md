# M8 security and tenancy review

Reviewed 17 July 2026 against the M8 release candidate. This is a
repository-grounded application review; it does not substitute for the pending
production deployment, restore drill, signed-in retailer tests, or a third-party
penetration test.

## Result

No known critical or high-severity application finding remains open in the
reviewed code. M8 is not release-approved because the production and
external-account evidence listed below is still outstanding.

## Closed findings

1. **Browser security policy was implicit.** `ApplySecurityHeaders` now applies
   `nosniff`, frame denial, a restrictive referrer policy, a microphone-limited
   permissions policy, authenticated no-store caching, and a nonce-based
   production CSP to both web and API middleware
   (`web/app/Http/Middleware/ApplySecurityHeaders.php:13-58`,
   `web/bootstrap/app.php:24-39`). The production header contract is exercised
   in `web/tests/Feature/M8OperationalControlsTest.php:123-140`.
2. **Message feedback route binding could reveal a foreign message.** `Message`
   now uses the same current-team route-binding concern as other tenant-owned
   models (`web/app/Models/Message.php:26-35`). Cross-family feedback is asserted
   as not found in `web/tests/Feature/ConversationFeedbackTest.php:79-100`.
3. **Recipe source links accepted active URL schemes.** Create and import
   validation now allow only HTTP(S), and a `javascript:` regression case is in
   `web/tests/Feature/M8OperationalControlsTest.php:152-162`.
4. **AI and automation consumption had no shared hard ceiling.** Per-family
   request, token, cost, automation-run, automation-step, and voice-session
   guards are centralised in `web/app/Support/UsageGuard.php:14-71`. Regression
   coverage includes pre-write rate limiting and all monthly/daily ceilings in
   `web/tests/Feature/M8OperationalControlsTest.php:36-84`.
5. **Export/deletion needed explicit secret and artifact handling.** Team
   exports enumerate all team-scoped tables and remove browser hashes, retained
   screenshot paths, and invitation tokens
   (`web/app/Actions/Privacy/ExportTeamData.php:13-135`). Team deletion requires
   owner authorisation and refuses to complete if retained screenshots cannot
   be deleted (`web/app/Actions/Privacy/DeleteTeam.php:18-51`).
6. **REACT-XSS-001 — raw QR SVG rendering.** The two-factor setup used
   `dangerouslySetInnerHTML` for a server-returned SVG. It now renders an encoded
   SVG only in image context, preserving the QR and accessible name without an
   HTML execution sink
   (`web/resources/js/components/two-factor-setup-modal.tsx:52-93`).
7. **REACT-NET-001 — mutable extension credential destination.** The extension
   stored an editable API base beside the connection token and reused it for
   authenticated requests. The API origin is now derived only from the packaged
   manifest, must match an exact host permission, and is never read from browser
   storage (`extensions/chrome/policy.js:35-57`,
   `extensions/chrome/background.js:3-46`,
   `extensions/chrome/popup.js:1-88`).
8. **REACT-CONFIG-001 / REACT-XSS-002 — public build input and script sinks.**
   Marketing destinations now reject active schemes and embedded credentials,
   the production gate rejects placeholders and cross-origin/wrong-path links,
   JSON-LD escapes script-closing input, and constant hero updates use
   `textContent` rather than `innerHTML`
   (`marketing/src/data/public-links.ts:1-38`,
   `marketing/scripts/production-config.mjs:1-51`,
   `marketing/src/layouts/BaseLayout.astro:27-87`,
   `marketing/src/scripts/hero-workflow.ts:482-531`).
9. **REACT-SUPPLY-001 — release checks were local-only.** Required CI now
   includes Composer/npm/Bun audits, the frozen marketing install and strict
   production build, extension policy and clean packaging, MySQL portability,
   backend coverage, frontend build, and browser journeys
   (`.github/workflows/ci.yml:11-161`). Hosted execution remains part of the
   production replay rather than being claimed locally.

## Tenancy and automation boundary evidence

- Authenticated product routes require a verified user and current family;
  models and policies resolve team-owned identifiers within that family.
- Automation step results must match both connection ID and family ID, are
  accepted only in the expected state, revalidate the retailer origin, and stop
  at takeover-sensitive paths
  (`web/app/Actions/Automation/SubmitAutomationStepResult.php:26-109`).
- Cross-family policy and route checks cover the automation run, step,
  connection, approval, and reconciliation models; foreign extension tokens
  are rejected (`web/tests/Feature/M6AutomationHandoffTest.php:800-857`).
- Checkout, payment, address, delivery, authentication, legal acceptance, and
  order submission remain prohibited extension and server actions.

## Open release conditions

1. **Production extension origin, release condition.** The source development
   manifest intentionally contains localhost and a non-functional placeholder.
   `npm run build:production -- --origin=https://host --version=x.y.z` rejects
   reserved/local origins and produces a package scoped to the exact deployed
   origin. That generated package must pass `npm run check`, be hashed, and be
   the build used for signed-in beta evidence. Do not ship the source
   development manifest.
2. **Production CSP and Reverb runtime, medium.** The header contract is tested,
   but the exact deployed asset, OpenAI Realtime, and Reverb connections must be
   exercised with the production nonce/CSP enabled. Any additional source must
   be narrowly added rather than weakening the policy.
3. **Infrastructure proof, release-blocking.** Run `chef:release:check --probe`,
   verify private object storage and encrypted sessions, exercise alerting, and
   complete the managed-backup restore drill in a new isolated environment.
4. **External safety proof, release-blocking.** Complete the real signed-in
   Woolworths/Coles and microphone tests from `docs/M8-LAUNCH.md`. Fixtures do
   not close these conditions.
5. **Dependency and dynamic verification, passed locally.** Composer, npm, and
   Bun reported no vulnerability advisory; Pint, PHPStan, frontend checks, React
   Doctor, extension checks, the full application suite, the 86.8% coverage
   gate, and the complete browser suite passed. The exact results are recorded
   in `docs/M8-VERIFICATION.md`; production-like replay is still required.
6. **Release evidence integrity.** Final approval now uses an HMAC-signed,
   exact-release/origin, content-free evidence schema. Unknown fields, query
   credentials, stale evidence, waivers, threshold failures, and post-signing
   edits are rejected. The key is separate release authority and must remain in
   protected environment secrets; see `docs/release/M8-APPROVAL.md`.

## Release rule

Reopen this review for any new external origin, stored credential, automation
action type, sensitive data category, payment capability, cross-family sharing
feature, or CSP relaxation. M8 can be approved only when no critical/high issue
is open and every release-blocking condition above has real evidence.
