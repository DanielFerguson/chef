# M8 accessibility review

Reviewed 17 July 2026 across the version 1 onboarding, planning, shopping, and
cooking journeys. The code and browser review found no open release-blocking
accessibility defect. Real assistive-technology feedback remains part of the
private beta rather than being represented as completed here.

## Interaction review

- The complete workflow remains typed; voice is optional and exposes mute,
  stop, reconnect, permission disclosure, and typed fallback.
- Planning provides direct controls and an accessible move alternative in
  addition to drag interaction.
- Shopping items, inclusion state, pantry state, approvals, pause, cancellation,
  and takeover are operable through labelled controls without drag interaction.
- Cooking exposes previous/next step navigation, a conventional step list,
  labelled timer/fullscreen controls, saved progress, outcome controls, and
  person-specific feedback labels.
- Destructive family/account actions use explicit confirmation; family deletion
  requires exact family name and password.

## Structure and responsive review

- Public information pages expose one `main` landmark and one page `h1`.
- Data and privacy sections have valid explicit heading IDs for every
  `aria-labelledby` relationship.
- Public navigation links provide at least 24px vertical tap targets.
- Browser assertions cover no horizontal overflow at 390×844 across first-plan,
  planning/recipes, shopping, retailer approval/takeover, cooking, privacy, and
  navigation journeys.
- Existing accessible primitives retain focus handling for dialogs, menus,
  checkboxes, selects, tooltips, and the mobile sidebar.

## Defects closed during review

1. Public pages inherited the authenticated app shell, creating nested `main`
   landmarks. They now opt out of that shell.
2. Missing local support configuration emitted an empty `mailto:null` link.
   Public contact copy now renders without a malformed control while production
   readiness still requires final contact data.
3. Data/privacy sections referenced heading IDs that did not exist. The shared
   heading component now accepts and renders an ID.
4. Header/footer navigation links were below the 24px mobile target minimum.
   Their interactive padding was increased without adding visual clutter.
5. A fresh-password challenge blocked users from reaching optional consent.
   Signed-in family members can now reach the overview; export and deletion
   retain stronger confirmation at the risky action.

## Evidence and follow-up

`composer test:browser` passes 29 tests and 243 assertions, including focused
desktop and 390×844 cases. The manual semantic pass and complete command record
are in `docs/M8-VERIFICATION.md`.

During private beta, include keyboard-only and screen-reader users where
possible, record issues without sensitive household content, and treat any core
journey without an equivalent operable path as release-blocking.
