# Backup and restoration drill

Chef's hosted version 1 uses Laravel Cloud managed MySQL 8.4 daily snapshots
with 14-day retention. Laravel Cloud restores a snapshot into a **new** database
cluster rather than overwriting production; that separation is part of the
recovery boundary. See the official [backup and restore
workflow](https://cloud.laravel.com/docs/resources/databases/laravel-mysql#database-backups).

## Recovery objectives

- Target recovery point: less than 24 hours for database loss.
- Target recovery time for the private beta: four hours.
- Automation screenshots are intentionally disposable and are not restored.
- The Chrome extension, application image, and public assets are rebuilt from a
  tagged release, not recovered from an instance filesystem.
- Object storage is not a database backup. Its retention and deletion policy is
  validated separately.

## Drill procedure

1. Record the production release, schema migration batch, row counts for each
   team-owned root, latest completed plan revision, and latest automation audit
   timestamp. Do not copy message bodies or screenshot content into the drill
   record.
2. Create a manual snapshot and note its identifier and completion time.
3. Restore the snapshot to a new isolated MySQL cluster in Sydney. Do not attach
   it to production.
4. Attach the restored database to a private, access-controlled recovery
   environment using the same tagged application release. Give the environment
   its own Valkey database, cache prefix, bucket, Reverb application, and
   `APP_KEY` copy from the source environment. Do not share queues with
   production.
5. Run:

   ```shell
   php artisan migrate:status
   php artisan chef:release:check --probe
   ```

6. Sign in with a designated recovery account and verify one representative
   household can open its latest plan, shopping list, recipe, cooking outcome,
   and automation audit. Verify a second household cannot resolve any of those
   records.
7. Compare the recorded row counts and timestamps. Explain any difference from
   the snapshot time.
8. Delete the recovery environment and restored cluster after the evidence has
   been reviewed. Never repoint production during a drill.

## Real recovery

During an incident, pause deploys and automation, declare the incident owner,
and preserve the failed cluster. Restore to a new cluster, validate it through
the same isolated procedure, put Chef into shared-cache maintenance mode, attach
the verified cluster, run readiness checks, and only then reopen traffic. Keep
the previous cluster detached until reconciliation is complete.

## Required evidence

The M8 backup gate remains unchecked until a real managed snapshot has been
restored and the drill records:

- snapshot and restored-cluster identifiers;
- timestamps sufficient to calculate actual recovery point and recovery time;
- release and migration status;
- aggregate reconciliation results;
- authenticated same-team success and cross-team denial;
- cleanup confirmation;
- reviewer sign-off and follow-up issues.

After review, store the detailed drill record in the approved private evidence
system and transfer only the aggregate booleans, HTTPS evidence path, completion
time, operator, and bundle SHA-256 digest into the `backup_restore` gate from
`docs/release/M8-EVIDENCE.example.json`. The final approval command rejects a
restore older than 30 days, a waiver, missing reconciliation, or evidence that
contains arbitrary fields.
