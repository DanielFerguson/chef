# M8 adversarial launch review

Reviewed 17 July 2026 after the full local M8 gate. The code-level candidate has
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

## Open launch blockers

1. **Infrastructure and recovery:** provision staging/production in the chosen
   Sydney topology, pass `chef:release:check --probe`, prove Nightwatch web and
   worker traces/alerts, verify private object storage, and restore a managed
   MySQL backup into an isolated environment.
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
   evidence, then tag and publish the exact approved commit.

## Hanging-task check

- No local server or browser QA session was left running.
- No fixture, waiver, or anonymous retailer visit was counted as real external
  evidence.
- No production extension package, deployment, beta, backup restore, or version
  tag was created during this review.
- M8 remains incomplete and the release goal remains active until the open
  blockers above are satisfied.
