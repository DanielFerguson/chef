# Private family beta

This runbook defines the private beta evidence required before Chef can approve
version 1. The beta has not run yet. Fixture tests and product-owner waivers do
not satisfy this gate.

## Cohort and consent

- Recruit 3–5 Australian households that understand Chef is a release
  candidate and may fail.
- Obtain an explicit `beta_research` choice from each account whose attributed
  feedback will be reviewed. Product analytics remains a separate optional
  choice.
- Use dedicated or expressly consenting retailer accounts. Never request or
  record retailer passwords, payment details, or API keys.
- Record the app build, extension build, browser version, retailer, household,
  and test date without copying conversation, allergy, or screenshot content
  into the issue log.
- Give every participant the support address, privacy policy, terms, data
  controls, typed fallback, pause/cancel/takeover instructions, and the rule
  that checkout and payment remain their actions.

## Measured journeys

Each participant attempts planning, shopping, and cooking on their normal
device. At least two participants also use a mobile-width device. Voice and
retailer handoff are attempted only by participants who opt in to those
permissions.

| Journey | Version 1 success criterion |
| --- | --- |
| First-plan a-ha | At least 80% create, edit, and confirm a first usable plan without operator intervention; median time is 15 minutes or less. |
| Shopping list | At least 80% create and directly correct a usable list from a confirmed plan. |
| Retailer handoff | Each retailer completes at least 3 signed-in cart-preparation attempts across at least 2 consenting households, with at least 80% reaching reviewable-cart takeover without a prohibited action. |
| Cooking | At least 80% start a planned meal, preserve progress, and record an outcome without data loss. |
| Native voice | At least 5 real-microphone sessions prove disclosure, mute, stop, interruption/reconnect, transcript, and typed fallback; at least 80% connect successfully. |
| Reliability | No critical or high-severity issue remains open; every medium issue has an owner, release decision, and tested workaround where applicable. |
| Support | Every beta request is acknowledged within one business day and linked to an issue or an answer. |

For retailer runs, capture only content-free outcomes: connected, store context
preserved, items attempted, items reconciled, pause/takeover observed,
prohibited boundary observed, duration, status, and error code. Screenshots stay
inside Chef's configured retention boundary.

## Severity and stop rules

- **Critical:** checkout/payment or sensitive-data transmission without direct
  confirmation; cross-family disclosure; credential exposure; destructive data
  loss; or allergy safety represented as inferred truth. Stop the beta.
- **High:** a safety boundary can be bypassed, restore/deletion fails, a core
  journey has no viable fallback, or repeated automation can act after pause or
  revocation. Stop the affected journey and block release.
- **Medium:** material confusion, accessibility failure, recurrent recoverable
  error, or an important workflow requiring operator assistance. Assign and
  decide before release.
- **Low:** polish or copy issues that do not impair safe completion. Track with
  an owner or explicit deferral.

Any retailer navigation toward checkout, payment, sign-in, address, delivery,
or legal acceptance must stop for manual takeover. A participant can pause or
withdraw at any time.

## Evidence record

Maintain a private beta register outside application logs with:

- anonymised household and session identifier;
- consent state and date;
- deployed commit and extension package hash;
- journey outcome and measured duration;
- operational event or automation run identifier, without payload content;
- issue identifier, severity, owner, decision, and verification evidence;
- participant withdrawal or data-deletion request, if any.

## Exit decision

The release owner signs off only after the measured criteria are calculated,
all critical/high findings are closed and retested, the production-like release
probe and restore drill pass, and every deferred retailer/microphone check in
`docs/M8-LAUNCH.md` has real evidence. Otherwise the beta remains open and M8
remains incomplete.
