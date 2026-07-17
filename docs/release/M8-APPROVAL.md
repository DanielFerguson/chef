# M8 final approval evidence

The M8 Markdown checklists communicate progress; they do not authorise a version
1 tag. `chef:release:approve` is the final machine-enforced gate for the exact
deployed release.

## Evidence workflow

1. Copy `M8-EVIDENCE.example.json` to the private release workspace. Do not
   edit or commit the example as if it were evidence.
2. Complete each real-world check against the exact `APP_RELEASE` and production
   origin. A waiver, fixture, anonymous retailer visit, or local-only check must
   remain `pending`.
3. Put detailed evidence in the approved private evidence system. The manifest
   contains only its HTTPS path, lowercase SHA-256 bundle digest, operator,
   completion time, and required content-free metrics. Do not add notes,
   screenshots, conversation text, allergy data, credentials, signed query
   strings, or arbitrary fields; the schema rejects them.
4. Set `approved_at` only after every gate is complete. The approval window is
   24 hours; production and replay evidence expires after two days relative to
   approval, and other evidence has the bounded age encoded by
   `M8LaunchEvidence`.
5. In the protected release environment, set a random
   `RELEASE_EVIDENCE_KEY` of at least 32 characters. Keep it separate from the
   manifest and never commit it.
6. Validate and sign the completed manifest:

   ```shell
   php artisan chef:release:evidence-sign /private/m8-evidence.json \
     --output=/private/m8-evidence.signed.json
   ```

   Signing fails if a threshold is missed, evidence is stale, a field is
   missing, a waiver is present, or arbitrary content has been added.
7. On the deployed production candidate, run:

   ```shell
   php artisan chef:release:approve /private/m8-evidence.signed.json
   ```

   This re-runs the immutable production configuration contract, live
   database/cache/object-storage probes, exact release/origin match, all
   measured thresholds, and HMAC signature verification. Success means the
   exact deployment is eligible to tag; it does not create or publish the tag.
8. Record the signed manifest digest, command output, deployment identifier,
   and version tag in the private release record. Tag only the commit named by
   `APP_RELEASE`, then update `docs/M8-LAUNCH.md` and `docs/MILESTONES.md` with
   links to the non-sensitive evidence record.

## Required gates

- staging and production probes: configuration, DB, cache, object storage,
  worker, scheduler, tenant-private broadcasting, Nightwatch web/worker traces,
  and mail delivery;
- isolated backup restore with current schema, reconciled counts, private file
  proof, untouched source, recovery point no greater than 24 hours, and recovery
  time no greater than four hours;
- final privacy/terms/operator/processing-country/support approval;
- tenant isolation, deletion, export, and retention expiry;
- measured Woolworths and Coles signed-in handoffs;
- measured real-microphone Realtime sessions;
- private beta thresholds with zero critical/high issues;
- production replay of prior milestones, MySQL CI, full/browser suites,
  dependency audits, security review, configured cost ceiling, and measured
  automation failure rate no greater than 20%; measured monthly AI cost per
  active family must remain within the positive configured ceiling.

The evidence key protects against an edited manifest being accepted after
approval. Access to the key is release authority: restrict it to the release
owner and rotate it after suspected disclosure.
