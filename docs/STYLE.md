# Chef product style

Status: active interface direction after the July 2026 platform reset.

Chef should feel like a calm household conversation becoming a useful plan,
then a verified, reviewable basket and recipes that are easy to cook from.
Follow the brand tokens in
[BRAND.md](BRAND.md); use Codex and ChatGPT as spatial inspiration, not as a
pixel reference.

## Interaction model

Conversation is the primary planning interface. Structured UI appears when
review, comparison, direct manipulation, or repeated use is faster than another
message.

- Conversation owns intent, negotiation, explanation, and broad changes.
- Calendar and List own visible plan structure and direct edits.
- Recipes own durable cooking instructions.
- A dedicated basket page owns product, pack, source, total, and restoration
  evidence.
- Cooking mode owns progress and outcomes.
- The server remains authoritative for readiness, permissions, safety, and
  recipe-generation, grocery, connection, and basket state.

Do not introduce a separate questionnaire onboarding flow. The first plan is
onboarding.

## Application shell

Keep navigation quiet and content primary. The sidebar should expose Today,
Plans, and Recipes plus household/account controls. Do not reserve navigation
for features that are not implemented.

The plan page uses three views:

- Conversation as the default;
- Calendar for spatial understanding across dates;
- List for compact direct editing.

The composer remains persistent whenever conversation is available. Avoid
dashboard grids, excessive cards, gradients, novelty AI treatments, and
decorative status chrome.

## Planning conversation

Messages should read naturally and group by speaker. Structured proposal cards
may appear in the transcript when a decision is required. Actions use plain
verbs: `Accept`, `Replace`, `Reject`, `Move`, and `Approve`.

The composer accepts photos on every riff turn through a labelled `Add photos`
control and drag-and-drop. The whole composer is the drop target and shows a
stable dashed `Drop photos to add them` overlay while files hover over any of
its children. File picking is always available as the accessible alternative.
Reject invalid, duplicate, oversized, or excess photos immediately. HEIC and
HEIF previews may be prepared locally and sequentially; keep the original file
for upload, announce `Preparing photo preview…`, and disable Send only until
that preparation settles. A failed local preview may use a neutral fallback and
must leave authoritative format validation to the server.

Use the shadcn `Attachment` composition for selected and persisted photos.
Show neutral labels such as `Photo 1`, useful file size or processing state,
keyboard-operable removal before sending, and a full-card authorised image link
in the transcript. Place photos before message text. Distinguish `Uploading
photos…` from `Chef is replying`; never fabricate percentage progress. At four
photos, attachment groups scroll within the bubble with an edge fade rather
than widening the page.

When planning is not ready, ask one consolidated clarification that groups the
actual blockers. Do not drip one question per slot. Omitted participants may be
shown as editable provisional suggestions with a plain source label such as
`Based on last Tuesday` or `Everyone in this household`; manual editing makes
them explicit. Never visually or verbally imply that an allergy was inferred.

Show one plan-level approval card only when every slot is covered and every
participant is explicit or visibly provisional. The card summarises the
effective meal for every slot, people and servings, estimates, explicit
constraints, grocery policy, basket target, provisional sources, and what the
approval will trigger. Before a household grants standing consent, the primary
action is:

> Approve plan & prepare recipes

After the Coles account owner grants standing consent, it becomes:

> Approve plan & prepare Coles basket

The supporting copy should explain that Chef prepares one complete recipe batch
and that the household can keep chatting while recipe and basket work runs.

When the conversation is paused on an SDK `ConfirmPlan` request, reuse this
same authoritative card instead of adding approval buttons to the transcript.
Its primary action resumes the request as `Approve plan`; its secondary action
is `Keep editing`, which rejects the paused tool without changing the plan.
Keep both actions in deterministic submitting and error states and prevent
duplicate submissions. The pending card must survive reloads, but disappear
after either decision, a later planning message, any plan revision, or direct
approval. When no SDK request is pending, preserve the normal direct approval
action and its recipe or Coles-specific label.

After approval, project internal orchestration into exactly one calm public
state:

- **Preparing your Coles basket — you can leave this page** for every routine
  recipe, discovery, selection, and replacement phase;
- **Connect Coles** or **Continue with Coles** when owner action is required;
- **Review the revised plan** for one coherent budget or product-unavailable
  decision;
- **Basket ready** only when confirmed from the actual basket;
- **Needs attention** or **Stopped** with one actionable explanation.

Never display fabricated percentages or an ETA without pilot evidence. Put raw
phase labels under an optional `Preparation details` disclosure.

The progress card should stay secondary to the planning conversation. Do not
turn the plan into an operations dashboard.

## Calendar and List

Calendar prioritises days, meal occasions, people, and gaps. List prioritises
scan speed and editing. Both are projections of the same plan and must remain
consistent after an Inertia response.

Direct manipulation needs accessible alternatives. A move or replacement must
be available through labelled controls, not drag-and-drop alone. Selected state,
pending proposals, and errors need text or icons in addition to colour.

## Recipes

Recipe index pages prioritise title, useful summary, total time, and household
context. Recipe detail pages keep ingredients, preparation notices, equipment,
ordered method, storage guidance, and version identity legible.

Ingredients and method should remain useful without conversation. Quantities use
tabular numerals. Editing or creating a new version must never rewrite the
historical version attached to a planned meal.

## Coles connection and basket

Connection is just in time, not an onboarding questionnaire. With no standing
grant, approval starts recipes and leaves the run at `Connect Coles`. The
account owner then gets one primary connection action, an embedded private Live
View, the complete disclosure, direct Coles safety/agreement links, and an
unchecked consent control. Never preselect standing consent.

Live View is a short-lived capability. Keep it inside a focused dialog or
sheet, label the owner boundary, and stop/release the session when the person
leaves. Web copy may state that credentials are entered directly in the hosted
browser. Native copy must instead explain that the explicit keyboard relay
forwards text once and clears it without storage.

The basket page should make confidence legible:

- show `confirmed from the actual Coles basket` only for a verified final
  snapshot;
- lead a ready result with product count, verified total, and capture time;
- pair `uncertain` or `needs attention` with text and an icon, never colour
  alone;
- list the selected product, absolute pack count, line price, pack reasoning,
  low-confidence best-valid status, and expandable recipe sources;
- distinguish selected-product subtotal from the full Coles basket total;
- show capture time, previous/replaced line counts, and the time-sensitive
  price notice;
- offer restoration and owner-only review only when policy and state allow it.

Keep item reasoning collapsed. Show no more than three still-valid alternatives
with product, pack, captured price, and a concise policy comparison. `Prefer
next time` must say that it changes a future preference only. Grocery settings
may expose household policy and saved preferences, but must not become an
onboarding questionnaire.

Budget and unavailable-product recovery is one decision surface, not scattered
errors. Show the whole proposed plan diff. A budget overrun offers `Use this
basket` to the retailer account owner or `Review cheaper plan`; an unavailable
product offers one revised-plan review. Neither action may imply that Chef has
changed the plan before reapproval.

`Review in Coles` must say that automation is stopped and checkout, fulfilment,
and payment are human-controlled. Restoration is destructive to the current
basket and needs a clear confirmation.

## Cooking mode

Cooking mode should work with wet hands, divided attention, and a phone at arm's
length:

- large current-step text;
- generous previous/next targets;
- visible step position;
- timers near the step that created them;
- ingredients and preparation notices within easy reach;
- screen-wake support where the browser permits it;
- a clear finish/outcome action.

Feedback comes after the outcome and is optional. Ask one participant at a time
and explain that repeated patterns remain reviewable rather than silently
changing safety rules.

Save the participant's required sentiment as soon as they choose it, then move
into an optional inline questionnaire for portion, effort, cost, leftovers, and
free-form detail. Every optional step can be skipped, `Finish later` leaves the
saved rating intact, and editing a rating must preserve already-saved detail.

Questionnaires are focused structured subflows, not onboarding wizards or a
replacement for the visible whole-plan approval. Safety capture may use the
same progression only while existing household safety records remain visible.
Its final step must show the complete proposed rule and require an unchecked
explicit confirmation; the interface must never preselect or infer that
confirmation.

## Responsive behaviour

Desktop may place structured context beside the main workflow. Narrow screens
must preserve the complete typed journey in one column.

At 390 × 844 and similar sizes:

- the sidebar opens through the standard labelled trigger;
- the composer remains reachable without covering the active decision;
- composer and transcript attachment groups stay within the message width and
  scroll horizontally without page overflow;
- plan approval and retry actions use full-width or comfortably wrapping
  controls;
- grocery progress remains readable without hiding the conversation;
- basket products, totals, restoration, and confirmed/uncertain state fit
  without horizontal page scrolling;
- Calendar and List stay operable without horizontal page scrolling;
- cooking actions remain thumb-sized;
- dialogs fit within the viewport and keep their primary action visible.

Do not create a mobile-only reduced product.

## Native iOS

The native app should feel recognisably Chef while using platform conventions
instead of imitating the web shell. Use SwiftUI navigation, sheets, toolbars,
keyboard behaviour, Dynamic Type, VoiceOver, and system feedback naturally.

The first plan remains onboarding. Do not insert a profile questionnaire before
conversation. After authentication, a new household moves directly into its
first planning conversation; household facts and explicit safety constraints
are collected in context and remain visibly reviewable.

Conversation and the durable plan stay close together:

- the composer remains reachable while planning and recipe preparation run;
- streaming text is announced without repeatedly moving VoiceOver focus;
- List is the first compact plan projection and Calendar follows as a complete
  alternative view;
- proposals, participants, safety review, revision conflicts, and approval use
  labelled native controls rather than chat-only commands;
- cached state is clearly stale or offline and never presented as a confirmed
  mutation;
- destructive or safety-relevant actions require explicit, comprehensible
  confirmation.

Native basket preparation uses foreground polling only. Live View uses a
non-persistent web view and a separate `privacySensitive` relay control. The
relay input is hidden, cleared before the network operation completes, limited
to the focused remote field, and accompanied by labelled Tab, Enter, and
Backspace alternatives. Do not describe mobile keyboard support as proven
until the real-device gate passes.

Native grocery settings expose household policy only to owners/admins while
keeping saved product preferences revocable for authorised plan editors. Plan
approval leads with the authoritative brief and visually identifies provisional
participants and displayed replacements. Recovery presents one coherent meal
diff: non-owners receive explicit owner-action copy, consequential controls use
confirmation dialogs, and successful preference, target, policy, override, or
approval mutations refresh the authoritative state before another submission.

Use standard navigation destinations with lightweight identifiers. Preserve
scroll position and draft text when moving between Conversation and plan
projections. Support the complete typed journey before adding native voice.

## Content

Use Australian English and household language. Prefer short, direct copy:

- `family` or `household`, not `tenant`;
- `people eating`, not `users assigned`;
- `prepare recipes`, not `execute generation`;
- `prepare Coles basket`, not `execute retailer automation`;
- `confirmed`, `not confirmed`, and `needs attention`, not ambiguous success;
- `try again`, not provider or queue terminology.

State what Chef did, what remains, and what the person can do next. Never present
an inferred allergy, completed mutation, or ready recipe without durable
evidence.

## Accessibility

- Use semantic headings, landmarks, forms, lists, and buttons.
- Every icon-only action requires an accessible name.
- Preserve visible focus and logical keyboard order.
- Meet WCAG AA contrast for text and controls.
- Do not use colour as the only state signal.
- Announce streaming, preparation, retry, and saved-feedback status without
  repeatedly stealing focus.
- Use `aria-live` or native announcements for basket phase changes without
  reading the whole product list again.
- Keep consent, restoration, and review controls reachable with keyboard,
  Switch Control, and VoiceOver.
- Respect reduced motion.
- Provide labels and error messages close to the affected control.

## Visual QA

For workflow changes, verify:

- conversation, Calendar, and List;
- draft, approval, preparing, failed, retry, and ready states;
- first connection, standing-consent reuse, blocked products, restoration,
  failed and uncertain baskets, and owner handoff;
- Recipes index and detail;
- cooking before, during, and after a meal;
- desktop and narrow-screen layouts;
- native Dynamic Type, VoiceOver, reconnect, and the real-device input relay;
- keyboard navigation, focus, and JavaScript console output.
