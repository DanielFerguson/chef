# M8 adversarial launch review

Reviewed 17–18 July 2026 after the full local M8 gate. The code-level candidate has
no known critical or high-severity application defect, but version 1 is **not
ready to publish** because external launch evidence remains deliberately
unchecked.

## Findings closed in this review

1. **False-positive release configuration:** example URLs, placeholder legal
   copy, and a placeholder support address could satisfy a simple “filled”
   check. `ReleaseReadiness` now rejects reserved/example hosts, invalid support
   email, mismatched public-policy hosts, replacement text, and non-final
   processing-country copy. A regression test covers every placeholder field.
2. **Consent hidden behind reauthentication:** the Data and privacy overview
   required a fresh password, blocking routine optional-consent changes. The
   overview now requires normal verified authentication/current-family scope;
   export and deletion preserve action-level confirmation.
3. **Malformed public structure and contact:** public pages inherited the app
   shell and rendered `mailto:null` locally. Public/error pages now use their own
   layout contract and render safe fallback contact copy while the production
   gate continues to require final values.
4. **Broken section labels and small mobile targets:** missing heading IDs and
   sub-24px public navigation targets were corrected and covered by browser
   tests.
5. **Extension package could be shipped with a placeholder origin:** a
   production builder now requires an exact non-placeholder HTTPS origin and
   numeric version, emits only that Chef host plus the two retailers, and
   pre-fills the same app origin. The development manifest remains explicitly
   non-distributable.
6. **Telemetry integration broke a direct test boundary:** the recipe drafter's
   new shared usage dependencies invalidated one direct-construction test. The
   test now resolves the real container boundary and uses a real scoped family;
   the full suite passes.
7. **External gates were documentation-only:** a release owner could tag a
   deployment without a machine check tying evidence to the exact release.
   `chef:release:approve` now requires fresh, measured, content-free evidence
   for every external gate, live database/cache/object-storage/worker/scheduler
   probes, and a valid HMAC signature. Waivers, fixtures, arbitrary notes,
   query-string secrets, stale evidence, missed thresholds, and post-signing
   edits fail.
8. **The production topology could redefine itself:** `CHEF_RELEASE_*`
   variables allowed an environment to make SQLite, sync queues, file sessions,
   null broadcasting, or local storage look like the expected production
   contract. The MySQL/Redis/Reverb/S3 contract is now immutable application
   configuration; only credentials and endpoints remain environmental.
9. **The extension token destination was mutable:** the popup stored an editable
   API base beside the connection token and both extension surfaces trusted it.
   The destination is now package-pinned, permission-matched, HTTPS-only outside
   localhost development, and covered by policy tests.
10. **Browser content still reached raw HTML sinks:** two-factor QR SVG used
    React's raw HTML escape hatch, marketing JSON-LD did not neutralise a closing
    script sequence, and the hero used `innerHTML` for constant labels. QR is now
    an encoded image, JSON-LD escapes `<`, and hero updates use text nodes.
11. **The public launch CTA was not a real application route:** marketing linked
    to `/start`, but Laravel had no matching route and the marketing production
    build accepted placeholders. `/start` now hands guests to registration and
    signed-in households to the dashboard; the production build rejects missing,
    unsafe, placeholder, cross-origin, and wrong-path destinations.
12. **Release checks could drift outside CI:** dependency audits, marketing, and
    extension packaging were local evidence only. They are now independent CI
    gates and the final signed manifest requires their hosted success.
13. **The full gate could fail across midnight:** one fourteen-day M3 test
    recalculated `today()` for each slot. It now uses the persisted plan start,
    removing the date-boundary flake without weakening the domain rule.
14. **Worker and scheduler evidence was self-reported:** the manifest required
    both components, but the release command proved only database, cache, and
    object storage. The scheduler now records a shared-cache heartbeat every
    minute and dispatches a no-risk job; release checks perform their own
    tokenised queue round-trip, reject a heartbeat older than three minutes,
    clean temporary probe state, and expose per-component JSON results. Final
    approval cannot pass if either runtime component is missing or stale;
    unavailable cache or storage adapters are contained as failed components
    rather than terminating the evidence command.

## Open launch blockers

1. **Infrastructure and recovery:** provision staging/production in the chosen
   Sydney topology, pass `chef:release:check --probe --json` including its real
   queue round-trip and scheduler heartbeat, prove Nightwatch web and worker
   traces/alerts, and restore a managed MySQL backup into an isolated
   environment.
2. **Final public configuration:** supply the actual app origin, operating legal
   entity, postal contact, support email, processing countries/subprocessor
   assessment, approved OpenAI token prices, and legal approval of the privacy
   policy and terms. The hardened release command must pass those exact values.
3. **Real external journeys:** build and hash the extension for the deployed
   host; complete signed-in Woolworths and Coles persistence/store/pause/takeover
   tests; complete real-microphone Realtime permission, interruption, reconnect,
   mute, transcript, and typed-fallback tests with production CSP enabled.
4. **Private beta:** run the cohort and measured success criteria in
   `docs/operations/BETA.md`; close and retest every critical/high issue and
   make an explicit decision on each medium issue.
5. **Production-like replay and release:** rerun all previously deferred gates
   against the approved deployment, verify the hosted MySQL CI result, record
   evidence, sign the content-free manifest, pass `chef:release:approve`, then
   tag and publish the exact approved commit.

## Hanging-task check

- No local server or browser QA session was left running.
- No fixture, waiver, or anonymous retailer visit was counted as real external
  evidence.
- No production extension package, deployment, beta, backup restore, or version
  tag was created during this review.
- M8 remains incomplete and the release goal remains active until the open
  blockers above are satisfied.
