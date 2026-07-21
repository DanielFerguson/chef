# Chef product and interface style

Chef borrows the spatial confidence and restraint of the ChatGPT and Codex desktop experiences: quiet navigation, content-led workspaces, a strong composer, and progressive disclosure. It should not be a visual clone. The design language must serve planning, shopping, and cooking rather than software-development workflows.

## Design intent

Chef should feel:

- calm enough for Sunday planning;
- fast enough for a weeknight change;
- trustworthy enough for allergies, budgets, and shopping automation;
- focused enough to cook from with messy hands;
- warm without becoming decorative or childish;
- intelligent without constantly announcing that it uses AI.

The product's a-ha moment is not receiving a paragraph from an assistant. It is watching a useful family plan take shape from an ordinary conversation.

## Experience principles

### Content is the interface

Prefer the meal plan, shopping list, recipe, or conversation itself over dashboards of summary cards. Chrome should be quiet and stable.

### Conversation and structure coexist

Natural language expresses intent. Structured UI shows durable state and enables precise changes. A message that schedules meals should visibly update the plan inspector; it should not leave the result trapped in prose.

### Progressive disclosure

Show the next useful decision. Avoid presenting every setting, filter, model, metric, and stage at once.

### Direct manipulation remains first-class

Anything the assistant can change should also be inspectable and, where practical, directly editable. Dragging a meal, ticking a list item, and changing servings must not require a prompt.

### Trust is visible

Distinguish:

- confirmed facts from suggestions;
- allergies from dislikes;
- estimates from actual prices;
- planned items from ordered products;
- background activity from actions awaiting approval.

Safety has three visible states: not reviewed, reviewed with no restrictions
reported, and reviewed with explicit rules. Never render an empty constraint
list as proof that no allergies exist. A plan stores the reviewed safety
context separately from confirmation and requires another review whenever its
participants or explicit constraints change.

### Feedback stays lightweight

Assistant responses expose quiet thumbs-up/down controls after the response is
complete. A reaction is saved immediately; optional reason tags and written
context appear only after the person chooses to add detail. Do not interrupt a
conversation with modal feedback requests.

Ask for broader experience feedback only at meaningful boundaries such as
planning confirmation, shopping review, and cooking completion. Checkpoint
feedback is dismissible, does not block the next stage, and is not presented as
an automatic change to Chef's memory or model.

At a completed boundary, show the next-stage handoff before its optional
feedback checkpoint. The handoff remains visible after refresh and provides one
clear action into the next available surface; saving or skipping feedback must
not be the action that advances the workflow.

### One product across input modes

Typing, dictation, and native voice operate the same plan. Voice must not create a separate navigation model or hidden state.

## Application shell

### Left rail

The desktop left rail is persistent and narrow. It contains:

- `New meal plan`;
- Today;
- Calendar;
- Shopping;
- search;
- recent plans grouped by useful state such as Draft, Upcoming, Active, and Completed;
- team switcher and account controls at the bottom.

Do not place a large month calendar permanently in the rail. Calendar is a destination; the rail is navigation.

Plan titles should be human and date-aware, for example `Winter weeknight plan` or `16–21 July`, not database-like identifiers.

### Top bar

The top bar communicates context and owns compact controls for switching views
within the current artifact. Do not repeat those controls in a second local
toolbar beneath it:

- editable plan title;
- date range;
- Conversation, Calendar, and List views for a meal plan;
- current phase or important milestone;
- participant summary;
- share or invite action;
- contextual overflow actions.

Keep it one quiet row on desktop. On small screens, collapse secondary metadata
and retain icon-labelled view controls with accessible names.

### Main workspace

The central column holds the primary activity:

- conversation during planning and onboarding;
- shopping list during shopping;
- recipe steps during cooking;
- outcomes during review.

The conversation should have a comfortable readable width rather than stretching across the viewport. Structured artifacts can break wider when comparison benefits from space.

### Inspector

The right inspector is collapsible and changes with context:

- onboarding: family profile and developing first plan;
- planning: calendar, meal slots, participants, cost and unresolved decisions;
- shopping: list summary, budget, source meals, product matches and automation status;
- cooking: ingredients, equipment, timers and substitutions;
- review: outcomes and person-specific feedback.

The inspector shows current truth. Conversation explains how it changed.

### Composer

The composer is persistent when conversation is available. It includes:

- multiline text entry;
- attach;
- microphone;
- send or stop;
- one contextual action when useful.

Avoid a toolbar of rarely used AI controls. Model selection, reasoning settings, and developer diagnostics do not belong in the household interface.

## Onboarding style

Onboarding uses the normal workspace rather than a wizard.

- Start with one welcoming prompt and one answerable question.
- Let the household speak naturally before showing forms.
- Translate answers into visible chips, people, constraints, and meal slots in the inspector.
- Ask at most one important follow-up at a time.
- Offer `Skip` and `I don't know yet` without penalty.
- Use quick reactions and meal cards when they reduce typing.
- Show lightweight progress such as `Family · Food · Week · First plan`, but never block value behind completing every category.
- End inside the useful first plan, not on a completion screen.

Permission prompts appear only at the moment of use and explain the benefit, scope, and fallback in one concise surface.

## Planning interactions

- Draft meal cards show name, participants, effort, key constraint fit, and estimated cost only when available.
- Selected meals visibly occupy dated slots.
- Drag-and-drop is supported, with an accessible move alternative.
- Changes caused by conversation animate subtly in the inspector so the connection is legible.
- Unresolved decisions are explicit and actionable.
- A stale shopping list shows a compact change summary rather than a generic warning.
- Do not show recipe-generation progress while the household is still choosing meals. Individual selections update the plan only.
- Filling the final slot starts one whole-plan recipe batch. Show one calm plan-level preparation or retry state, then reveal safety review and confirmation when every recipe is ready.
- Describe the number of remaining recipes in migrated or partially prepared plans; do not imply already-complete recipes are being regenerated.
- Explain that shopping follows confirmation; do not leave the household to ask what happens next.

Avoid gamified progress, excessive recommendation carousels, or a dense project-management board.

## Shopping interactions

The list should feel as fast as a lightweight document while remaining structured.

Shopping uses progressive disclosure and preserves the meal plan's natural
conversation. Before a useful list exists, show one primary preparation action
and calm recipe/list progress. Do not lead with budget controls, an empty item
editor, completion, or expanded manual ingredient forms. Once the generated
list is ready, guide the household through pantry review and household extras;
then reveal budget and product detail as optional next decisions. Manual recipe
ingredient entry is a recovery path, not the default experience.

- click or tap to edit;
- enter to add;
- drag to reorder where meaningful;
- tick to complete;
- annotate inline;
- default to calm grocery-aisle sections such as Fruit & Veg, Meat & Seafood,
  Dairy & Eggs, Bakery, Pantry, Frozen, Drinks, and Household; let the household
  correct a misplaced item from its context menu;
- allow alternate grouping by source meal or status when it becomes useful;
- reveal product and substitution detail on demand;
- keep estimated and actual totals visually distinct.

Default list rows stay compact enough for in-store use: one large completion
control, item name, quantity and unit, and quiet actions. Editing notes and
other fields is progressive. Pantry review is an explicit pass over likely
staples, never a bulk assumption that they are already available. Alternate
table views keep completion controls at least 20 by 20 CSS pixels and may
scroll horizontally rather than compressing controls into unusable targets.

These are progressive capabilities rather than permission to invent missing
catalogue data. M4 shows source meals and state inline in a stable generated
order. Aisle grouping, rejected-product history, and retailer-informed
reordering wait until the retailer catalogue and reconciliation work in M6.

Automation status should read like a calm activity log:

```text
Matching 34 items
28 added
3 need your choice
3 still searching
```

Approval requests state the proposed action and consequence. Do not use vague prompts such as `Allow Chef to continue?`.

Retailer connection appears just in time beneath a ready shopping list. Human
login uses a dedicated, recording-disabled browser surface with a plain
explanation that the model is absent. Never mix password/MFA entry with agent
activity or automatically continue into cart mutation after authentication;
return to Shopping for a second explicit start action.
The owner-only sign-in iframe may use clipboard read/write permission so the
person can paste a password-manager value or MFA code. Do not grant clipboard
permission to cart takeover or agent-controlled browser surfaces.

If an already-approved cart run pauses for reauthentication, the explicit
`I’ve signed in` action may return that same recording-disabled session to the
run after the protected cart check passes. This is a resume of the earlier
approved work, not a new cart-preparation approval.

Before that second action, complete bounded read-only retailer discovery and
show the durable product plan: exact products, genuinely ambiguous or unresolved
choices, and applicable explicit household safety constraints. Discovery is a
separate visible action and never authorises cart mutation. Keep each ambiguous
choice beside its candidates and each unresolved item beside the exact-product
editor. Only a fully exact plan exposes the product-plan review acknowledgement;
the separate safety acknowledgement remains required. Do not hide a blocked
state behind a disabled button alone.

If a retailer cart is non-empty, show its observed lines and three concrete
choices: merge, replace the existing cart, or cancel. Replace must name the
consequence. Progress stays item-oriented and quiet, while interventions name
the product, observed consequence, and available recovery. The final review
separates Chef-added, substituted, unavailable, changed, pre-existing, and
unresolved lines, and distinguishes the Chef subtotal from the whole-cart
total. The only checkout handoff is `Open Woolworths cart` in the household's
normal retailer experience.

Active runs offer `Pause and take over` only to the connection owner. The
manual surface must plainly say that the model is disconnected and recording
is disabled. Returning to automation is labelled `Reconcile and resume`, so it
does not imply that unverified manual cart edits are already accepted.

## Cooking mode

Cooking mode is deliberately different from planning:

- large type and generous targets;
- high contrast;
- one current step with adjacent steps available;
- ingredients and quantities reachable without losing place;
- screen-wake support where available;
- minimal navigation and no promotional content;
- timers that remain visible after moving between steps;
- substitutions from the actual shop, not only the original recipe.

The cook should be able to use the view at arm's length on a narrow screen.

## Mobile and responsive behaviour

Chef is responsive web-first.

Suggested mobile navigation:

- Today;
- Plans;
- Shop;
- Chat.

The desktop rail becomes a drawer. The inspector becomes a full-height sheet. Cooking mode becomes a focused full-screen flow. Preserve plan context when switching between chat and structured views.

## Visual language

### Brand identity

Chef uses the locked **Shared Table** identity: a paprika table between sage and
oat chairs on a white canvas. The mark expresses the product's central promise—a
household conversation becoming a shared, durable plan—without relying on chef
hats, utensils, or decorative AI imagery.

The production palette, typography, mark rules, asset inventory, and token
mapping are defined in [`BRAND.md`](BRAND.md). That document is the source of
truth for brand execution; this document remains the source of truth for product
interaction and interface style.

### Colour

Use white as the dominant canvas, quiet neutral grays for structure, and paprika
as the primary culinary accent. Sage and oat support the identity sparingly.
Colour communicates state before decoration. Avoid beige page washes: oat is an
accent, not the application background.

Initial semantic roles:

- background and elevated surface;
- foreground and muted foreground;
- border and focus ring;
- primary action;
- success or completed;
- warning or unresolved;
- destructive or unsafe;
- assistant activity;
- hard safety constraint.

Allergy and safety indicators must not rely on colour alone.

### Typography

- Use a highly legible sans-serif UI family.
- Keep conversation and recipe text comfortably readable.
- Use tabular numerals for prices, quantities, timers, and budget comparisons.
- Reserve monospace for identifiers or developer diagnostics, not ordinary shopping lists.
- Prefer sentence case throughout the product.

### Shape and elevation

- Use moderate radii and thin borders.
- Keep shadows soft and infrequent.
- Avoid nesting cards inside cards.
- Use whitespace and alignment before additional containers.
- Floating surfaces are for composers, menus, approvals, and temporary inspectors—not every section.

### Icons

Use one consistent outline icon family. Pair unfamiliar icons with labels. Avoid food emoji as primary navigation or status language.

### Motion

Motion should explain:

- where a newly scheduled meal went;
- that an assistant is actively working;
- that an inspector changed context;
- that an automation paused for approval.

Keep transitions short and respect reduced-motion preferences. Avoid ambient animation.

## Component approach

Use shadcn/ui primitives as the starting point and compose Chef-specific patterns from them. Prefer accessible primitives over custom interaction code.

Likely shared components:

- `AppShell`
- `PlanRailItem`
- `ConversationThread`
- `Composer`
- `ArtifactCard`
- `PlanInspector`
- `MealSlotCard`
- `PersonChip`
- `ConstraintBadge`
- `ShoppingListEditor`
- `BudgetSummary`
- `AutomationActivity`
- `ApprovalCard`
- `RecipeStepView`
- `PermissionPrompt`

Do not build a large abstract design system before the first slice reveals repeated needs.

## Writing and conversation style

Chef is concise, warm, and practical.

- Lead with the recommendation or changed outcome.
- Ask one material question at a time.
- Explain why a suggestion fits using household context.
- State uncertainty plainly.
- Never call an inferred preference a fact.
- Never soften allergy language.
- Avoid congratulatory filler after routine actions.
- Use the household's names when it clarifies participation or disagreement.
- Say `I added the items to your cart for review`, never `I placed your order`.

## Accessibility baseline

- Meet WCAG 2.2 AA for public version 1.
- Full keyboard access for planning and shopping interactions.
- Visible focus states.
- Accessible alternatives to drag-and-drop.
- Announce streamed messages and plan changes without overwhelming screen readers.
- Use labelled controls and meaningful status text.
- Support zoom and large text without hiding core actions.
- Maintain sufficient touch targets in cooking and shopping modes.
- Caption or transcribe voice interactions.
- Never encode safety, completion, or approval state with colour alone.

## Design review checklist

Before accepting a new surface, ask:

1. Is the main household task immediately obvious?
2. Is durable state visible outside the conversation?
3. Could direct manipulation be faster here?
4. Are suggestions, facts, constraints, and approvals distinguishable?
5. Does the surface work by keyboard and on a narrow screen?
6. Is any information duplicated without helping orientation?
7. Does the interface remain calm during streaming or automation?
8. Is the user still in control of consequential actions?
