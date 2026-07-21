# Woolworths Live Pilot Runbook

## Purpose

Finish the remaining M6 evidence with an authorised Woolworths account without
allowing Chef to authenticate, check out, pay, or widen its own permissions.
Run commands from `web/`. Keep all three release flags false until their named
gate has passed.

## 1. Preserve the baseline

- Keep the pre-pilot implementation commits and record `git status`.
- Run `php artisan chef:automation:status` and save the non-secret output.
- Confirm `WOOLWORTHS_CONNECTION_ENABLED=false`,
  `WOOLWORTHS_CART_MUTATION_ENABLED=false`, and
  `WOOLWORTHS_NORMAL_APP_SYNC_PROVEN=false`.

## 2. Review the product and safety preflight

- Use a current, non-stale list with no unresolved meals.
- Confirm Shopping shows exact matches, automatic-search scope, and every
  applicable explicit constraint.
- Confirm the server rejects missing safety acknowledgement and unapproved
  automatic search.
- For a strict-constraint household, confirm every item requires an exact
  Woolworths product-detail URL and that substitutions are off.

## 3. Start the dedicated worker

- Development: `composer dev` listens to `default`, `ai`, and `automation`.
- Pilot/production: run a supervised worker dedicated to
  `php artisan queue:work --queue=automation --tries=1 --timeout=0`.
- Confirm failed jobs and application logs are observable before mutation.

## 4. Configure Browserbase and enable connection only

- Set `BROWSERBASE_API_KEY` and `BROWSERBASE_PROJECT_ID` outside source control.
- Keep the configured Australian region/proxy and recording disabled.
- Run `php artisan chef:automation:status`; it must report both Browserbase
  values and the compiled worker as ready.
- Set only `WOOLWORTHS_CONNECTION_ENABLED=true`, clear the configuration cache,
  and rerun the status command. Leave cart mutation false.

## 5. Prove recording-disabled authentication persistence

- The connection owner opens Chef's secure Live View and personally enters the
  Woolworths password and MFA. The model must remain detached.
- Verify recording is disabled in both Chef and Browserbase session metadata.
- Finish sign-in, allow the Context sync delay, and close the login session.
- Open a fresh session from the same Context and prove a protected cart page is
  authenticated without re-entering credentials.
- Revoke or expire one session and prove the owner-only reauthentication path.

## 6. Run one-item, then five-item cart trials

- Use a low-risk isolated list and an account cart whose starting state is
  known. Enable `WOOLWORTHS_CART_MUTATION_ENABLED=true` only after step 5.
- First run: one exact product, no checkout. Verify identity, quantity, price,
  idempotent retry, immutable snapshot, and normal-site/app visibility.
- Second run: at least five items spanning search, exact matches, quantity
  changes, and one unavailable or ambiguous result. Verify each mutation and
  the final Chef subtotal versus whole-cart total.
- Never navigate through checkout, addresses, terms acceptance, or payment.

## 7. Exercise interventions and normal-app visibility

- With controlled test data, prove merge, replace, cancel, price-limit,
  substitution, bot/sensitive-screen stop, manual takeover, reconciliation,
  session loss, Context revocation, and reauthentication.
- For replace, prove removal happens only after the explicit choice.
- Open the normal Woolworths site or app independently and verify the same cart
  persists. Record the observation; do not set the sync-proof flag from a
  Browserbase Live View alone.

## 8. Link the reviewed cart to order history

- Complete checkout manually in the normal Woolworths experience if desired.
- Mark the matching Chef list complete, enter the actual total, and record the
  order from the ready cart snapshot.
- Verify the order inherits Woolworths and its immutable lines match the
  reconciled Chef-added cart lines. Pre-existing and unresolved cart lines must
  not become Chef order lines.

## 9. Close the evidence gate

- Run the full backend, browser, frontend, build, migration, audit, and React
  Doctor gates.
- Record dates, account type without identifiers, Browserbase region,
  recording state, one/five-item outcomes, interventions, normal-app proof,
  costs, retailer/privacy review, and any deviations in `docs/MILESTONES.md`.
- Set `WOOLWORTHS_NORMAL_APP_SYNC_PROVEN=true` only after independent normal-app
  proof. M6 remains open until Woolworths evidence and the milestone's remaining
  Coles/extension scope are explicitly resolved or re-scoped.

## Stop and rollback

At any unexpected account, retailer, privacy, or cart state: cancel the run,
set cart mutation false, stop the automation worker, disconnect the retailer
connection to delete the Browserbase Context, and inspect redacted application
events. The household performs any cart cleanup and all checkout actions.
