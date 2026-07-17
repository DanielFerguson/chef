# M8 launch record

This record separates implemented launch controls from evidence that can only be
produced in an authorised production-like environment. `M8` in
`docs/MILESTONES.md` remains the release authority.

## Platform decision

- [x] Laravel Cloud, Sydney, `web/` monorepo root selected.
- [x] Managed MySQL 8.4 selected for the hosted service; SQLite retained for
  local and personal use.
- [x] Valkey, dedicated queue worker, Laravel scheduler, private object storage,
  managed Reverb, and Nightwatch encoded in the production contract.
- [x] Production configuration and dependency probes implemented by
  `chef:release:check --probe`; the machine result includes database, cache,
  private object storage, an on-demand queue-worker round-trip, and a fresh
  scheduler heartbeat.
- [x] MySQL migration and test portability added to CI.
- [x] Required CI jobs enforce backend coverage and Composer audit, MySQL
  portability, frontend build and npm audit, strict marketing build and Bun
  audit, extension policy/package checks, and browser journeys.
- [ ] Staging resources provisioned and the release probe passed.
- [ ] Production resources provisioned and the release probe passed.
- [ ] Managed backup restored through `docs/operations/RESTORE.md`.

## External acceptance evidence

These checks require accounts, hardware, infrastructure, or participants. They
must never be checked from fixtures or a product-owner waiver.

- [ ] Dedicated or consenting Woolworths beta account: persistence, selected
  store, pause, takeover, and pre-checkout boundary.
- [ ] Dedicated or consenting Coles beta account: persistence, selected store,
  pause, takeover, and pre-checkout boundary.
- [ ] Real microphone and OpenAI Realtime session: disclosure, revocation,
  interruption, reconnect, mute, transcript, and typed fallback.
- [ ] Private family beta: participant consent, first-plan success metric,
  retailer handoff metric, issue log, and release-blocker closure.
- [ ] Production-like replay of every earlier fixture-only or local-only gate.

## Release approval

- [x] No known critical or high-severity application issue remains open in the
  repository review.
- [ ] Tenant deletion, account export, retention expiry, and restore are proven.
- [x] AI and automation quotas and monthly cost ceilings are implemented and
  tested; production rates and ceilings still require final configuration.
- [x] Accessibility and responsive core-journey evidence is linked.
- [x] Privacy, terms, support, onboarding help, and release notes are available
  without authentication; final operator/contact values and legal approval
  remain required.
- [x] Public marketing production configuration rejects placeholder or unsafe
  URLs and targets the tested `/start` and `/login` application handoffs.
- [ ] Version 1 tag points to the exact approved deployment.
- [x] Final machine gate implemented with the immutable production contract,
  live database/cache/object-storage/worker/scheduler probes, a content-free
  signed evidence manifest, freshness limits, and measured acceptance
  thresholds.
- [ ] `chef:release:approve` passes on the exact production candidate and its
  signed manifest digest/output are stored in the private release record.

The manifest contract, template, and commands are in
`docs/release/M8-APPROVAL.md`. A signed manifest records evidence; it does not
replace any real-world check or create the version tag.
