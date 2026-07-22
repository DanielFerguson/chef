# Chef commercial and marketing strategy

Status: **Working strategy for validation — 16 July 2026; product finish-line
update 22 July 2026**

This document defines how Chef should be positioned, packaged, sold, and
explained on its future Astro marketing site. It is a commercial and content
brief, not a release claim. The [README](../README.md) remains the product source
of truth, [MILESTONES.md](MILESTONES.md) owns delivery status,
[IMPLEMENTATION.md](IMPLEMENTATION.md) owns technical boundaries, and
[STYLE.md](STYLE.md) plus [BRAND.md](BRAND.md) own product and brand execution.

**Product update (22 July 2026):** version 1 retailer shopping now ends at
in-Chef fulfilment selection and confirmed order placement with the retailer's
default card on file (see
[`plans/2026-07-22-retailer-order-placement-design.md`](plans/2026-07-22-retailer-order-placement-design.md)
and M6.2). Older “checkout stays yours” / “never an order-confirmation screen”
claims in this file are superseded for future copy; a full commercial rewrite
pass remains on the M6.2 checklist.

Where this document describes a future version 1 capability, public copy must
not present it as available until its milestone exit evidence is complete.
Pricing, conversion targets, and channel choices are hypotheses to test rather
than settled facts.

## Decision summary

Chef should be sold as **the household meal-coordination product that turns a
real conversation into a plan, a trustworthy shop, and an easier week**.

The initial commercial decisions are:

- Lead with the weekly household outcome, not AI, recipes, nutrition, or grocery
  price comparison.
- Focus first on Australian households in which one person carries most of the
  planning load but several people affect the decision.
- Use the first real meal plan as both onboarding and the product demonstration.
- Sell one household subscription rather than individual seats or fragmented
  feature tiers.
- Test `AUD $9/month` and `AUD $89/year` as the public version 1 price, with
  annual billing presented first. The annual option saves `AUD $19` and is
  equivalent to `AUD $7.42/month` when billed yearly.
- Offer a complete first-plan trial without requiring card details. Do not add a
  permanent free plan before activation, retention, and AI costs are known.
- Recruit a small paid design-partner cohort before a broad public launch.
- Use a prelaunch site while Chef is still in private testing, then switch to
  version 1 acquisition copy only when the release gates are satisfied.
- Build the marketing site in Astro, with real product states as the dominant
  visual material and static landing pages for high-intent search and ads.
- Prototype the homepage, how-it-works page, pricing page, and one focused
  acquisition page in Paper before building the Astro component system.

## 1. Commercial thesis

### The problem Chef owns

The weekly food problem is not simply finding recipes. It is coordinating:

- who is eating and when;
- what different people will accept or enjoy;
- allergies, exclusions, time, budget, leftovers, and existing ingredients;
- decisions made in conversation with the plan that must be used later;
- the handoff from meal ideas to a reliable shopping list and retailer trolley;
- what actually happened, so the next plan is easier.

Most products expose one part of that workflow: recipe discovery, a calendar, a
list, nutrition targets, price comparison, or cart import. Chef's commercial
opportunity is to make the whole household decision durable and executable.

### Recommended category

Use this internal category definition:

> **Household meal coordination**

For customer-facing copy, use familiar language such as `family meal planner`,
`shared meal plan`, and `meal planner with shopping list`. Do not expect people
to search for a new category before Chef has earned the right to define one.

### Positioning statement

> For Australian households tired of one person carrying the weekly dinner
> decisions, Chef is a shared meal-planning companion that turns an ordinary
> conversation into a plan, shopping list, and reviewed grocery trolley. Unlike
> recipe catalogues and generic meal planners, Chef remembers the people,
> constraints, preferences, and decisions behind the week while keeping the
> plan visible and editable.

### Short pitch

> Tell Chef how the week looks. See a meal plan take shape, adjust it together,
> turn it into a shopping list, and prepare a Woolworths or Coles trolley for
> review.

### The promise

> **A calmer answer to “what are we eating this week?”**

The promise is emotional and operational. Chef reduces the repeated negotiation
and preserves the outcome as useful household state.

### The product's first proof

The best demonstration is not a blank chatbot or a gallery of food. It is one
ordinary request becoming structured state:

> Plan five dinners for four. Tuesday is only two of us, Mia won't eat
> mushrooms, Sam gets home late on Thursday, keep weeknights under 35 minutes,
> and use the spinach before it goes.

The product visual should then show:

- five dated meal slots;
- Tuesday's different participants and servings;
- the explicit mushroom preference attached to Mia;
- a simpler Thursday meal with an explanation;
- the spinach used in an early meal;
- a visible review-and-confirm action;
- later, the traceable shopping list generated from the confirmed plan.

That sequence communicates Chef's differentiation more clearly than a list of
AI features.

## 2. Current truth and claim states

Marketing work needs a shared vocabulary for claim maturity.

| State | Meaning | Public treatment |
| --- | --- | --- |
| **Implemented** | The current product and its tests support the behaviour. | May appear in private-beta copy, subject to production readiness. |
| **Version 1 required** | The behaviour is part of the release definition but its owning milestone is incomplete. | May appear only as clearly labelled `coming to version 1` or future vision. |
| **Post-version 1 candidate** | The idea is not committed to version 1. | Keep out of acquisition copy and pricing tables. |
| **Commercial hypothesis** | Positioning, pricing, or performance has not been validated with customers. | Test directly; never present it as customer evidence. |

### Claim ledger

| Claim area | State on 16 July 2026 | Safe language now | Version 1 language after evidence |
| --- | --- | --- | --- |
| Typed conversational planning | Implemented | `Talk through a plan and see it take shape.` | Same. |
| Durable arbitrary-date plans | Implemented | `Plan a few days, a week, or the span that suits you.` | Same. |
| Household people and participation | Implemented | `Plan around who is actually eating.` | Same. |
| Explicit preferences and safety constraints | Implemented | `Keep stated preferences and allergies visible and editable.` | Same; never imply a safety guarantee. |
| Shared family access and invitations | Implemented | `Invite another household member into the same plan.` | `Plan together in one shared household.` |
| Versioned recipes and direct plan editing | Implemented | `Keep the recipe used for each planned meal and adjust the plan directly.` | Same. |
| Structured, traceable shopping list | M4 in progress | `Private testing now covers a plan-derived, editable shopping list.` | `Know why every generated item is on the list.` |
| Retail products, budgets, and order snapshots | Version 1 required through M4 | `In development.` | `Review products and the estimated total before shopping.` |
| Cooking mode and household learning | Version 1 required through M5 | `Planned for version 1.` | `Cook from tonight's plan and make the next week easier.` |
| Woolworths and Coles order placement | Version 1 required through M6.2 | `Planned: prepare the trolley, choose a slot in Chef, confirm, and Chef places the order with your default card on file.` | `Chef can prepare the shop and place the order after you confirm the slot.` |
| Native voice | Version 1 required through M7 | `Voice is planned; typing is available in testing.` | `Talk or type into the same plan.` |
| External access through MCP | Version 1 required through M8 | Developer or integration documentation only. | Do not make this a household homepage headline. |
| Public privacy, operations, and support readiness | Version 1 required through M9 | `Private beta` or `request access`; do not imply public availability. | Public signup only after the M9 gate. |
| Saves time, money, or food waste | Unproven commercial hypothesis | `Designed to reduce repeated planning and unnecessary re-entry.` | Use quantified claims only after Chef-specific measurement. |

### Claims that require evidence before publication

Do not publish statements such as these without a defined method and real Chef
data:

- `Save five hours every week.`
- `Cut your grocery bill by 30%.`
- `Never waste food again.`
- `Allergy-safe meal plans.`
- `The cheapest possible shop.`
- `Perfect recommendations for everyone.`
- `Fully autonomous grocery shopping without confirmation.`
- `One-click checkout` that skips fulfilment choice or confirmation.

Testimonials, star ratings, household counts, time savings, budget savings, and
completion rates must all be attributable to real customers. Do not use
placeholder testimonials in a public build or add review structured data before
the underlying reviews exist.

## 3. Market frame and differentiation

This is a directional snapshot taken on 16 July 2026, not a complete competitive
audit.

### What customers can already buy

| Market cluster | Current examples | Common promise | Commercial implication for Chef |
| --- | --- | --- | --- |
| Low-cost list and planning tools | [AnyList](https://www.anylist.com/features), [Plan to Eat](https://www.plantoeat.com/) | Organise recipes, plan a calendar, and generate a list. AnyList lists household access at US$14.99/year; Plan to Eat lists US$49/year. | A calendar and automatic list are expected capabilities, not a premium story on their own. |
| Personalised recipe and meal-plan subscriptions | [Samsung Food+](https://support.samsungfood.com/hc/en-us/articles/32709269852052-What-s-Included-in-Your-Samsung-Food-Subscription), [ReciMe](https://recime.app/help/en/articles/11596201-existe-t-il-une-version-gratuite-de-l-application) | Tailored meal plans, recipe import, nutrition, grocery lists, and cooking support. | `AI meal plans` and `import recipes` are crowded claims. |
| Australian price-comparison and cart tools | [MealCC](https://mealcc.com.au/), [Pan Mate](https://www.panmate.app/), [Pinch](https://pinch-app.com/about/), [Recipes4Me](https://www.recipes4me.com.au/) | Compare Coles, Woolworths, Aldi, or IGA prices; build a list; move items toward a trolley. | Grocery savings and cart import are active local battlegrounds. Chef should not depend on exclusivity here. |
| Retailer-owned experiences | [Coles app](https://www.coles.com.au/about/coles-app) | Shop, save lists, and navigate the retailer's own catalogue. | Chef must add value before the retailer session and preserve household intent independently of one store. |

### What Chef should not compete on first

- the largest recipe catalogue;
- nutrition or medical guidance;
- the broadest set of supermarket price feeds;
- the cheapest standalone grocery list;
- a generic chatbot that produces seven recipe names;
- fully autonomous checkout;
- a permanent stream of food content.

### Chef's defensible wedge

Chef's wedge is the **context behind the plan**:

1. It starts with the real household conversation.
2. It models people separately, including who is eating each meal.
3. It keeps explicit safety rules, preferences, evidence, and corrections
   inspectable.
4. It turns loose intent into structured, directly editable state.
5. It preserves the connection from the chosen recipe version to the shopping
   requirement and retailer product.
6. It keeps external shopping actions reviewable and leaves checkout to the
   person.
7. It can learn from what the household actually cooked and liked without
   turning guesses into facts.

Competitors may implement individual elements. The message should focus on the
coherent workflow and product behaviour, not claim that no competitor has a
particular checkbox.

### Naming and discoverability risk

`Chef` is warm and appropriate inside the product, but it is an extremely broad
term for search, app-store discovery, domain ownership, and legal clearance.
This does not need to block the marketing design, but it should be resolved
before meaningful advertising or public launch spend.

Near-term mitigation:

- consistently pair the name with `meal planning for real households`;
- use descriptive page titles and headings rather than expecting the brand name
  to carry search intent;
- validate domain, trademark, social, and app-store availability separately;
- keep the Shared Table identity distinctive and consistent.

## 4. Target customers

### Primary initial customer

The first customer is an Australian household where:

- one adult currently carries most of the planning and shopping load;
- two or more people affect what can be cooked;
- the household plans at least four dinners in a normal week;
- schedules, preferences, leftovers, or serving counts vary during the week;
- the household already shops online at Coles or is comfortable reviewing a
  prepared trolley there;
- the planner values control and clarity more than endless recipe discovery;
- the household will try a responsive web product before native apps exist.

This person may be a parent, partner, carer, or housemate. Marketing can use
`family` where it matches search intent, but the product should continue to use
the more inclusive `household` where appropriate.

### Primary job to be done

> When the next several days are coming up, help me turn everyone's needs and
> the reality of the week into one agreed plan and shop, so I am not carrying
> the whole decision in my head or re-entering it across different tools.

### Supporting jobs

- Give us ideas that fit this household rather than generic popular meals.
- Show everyone what is planned without replaying the conversation.
- Let me change one thing without rebuilding the week.
- Make the list trustworthy enough that I do not need to audit every quantity.
- Remember useful preferences while letting us correct Chef when it is wrong.
- Prepare the retailer work without taking away my control of the order.
- Put tonight's recipe and preparation needs in front of the person cooking.

### Buying triggers

- returning to work after leave;
- a new school term or changed family schedule;
- moving in together;
- a partner or child developing a new stated restriction;
- grocery spend feeling uncontrolled;
- using takeaway too often because no decision was made;
- frustration with a notes app, whiteboard, shared chat, and retailer app split;
- a recurring Sunday planning ritual that consumes too much attention.

### Secondary audiences

These can support targeted pages after the primary workflow converts:

- couples with different tastes or schedules;
- households managing frequent guests or changing serving counts;
- batch-cooking households that want leftovers to become explicit meals;
- people who plan for an older family member or shared household;
- existing recipe collectors who want their recipes to participate in a plan.

### Not the initial customer

- a person who only wants recipe entertainment;
- a bodybuilder or athlete buying a specialised macro optimiser;
- someone seeking medical nutrition advice;
- a bargain hunter whose only requirement is cross-retailer unit pricing;
- a customer who expects Chef to submit payment or place an order autonomously;
- a household that refuses any manual review of inferred preferences or product
  matches;
- a person who requires a native mobile application at launch.

## 5. Messaging system

### Message hierarchy

Every acquisition surface should follow this order:

1. **Outcome:** a calmer, shared answer to what the household will eat.
2. **Mechanism:** talk through the week while a real plan takes shape.
3. **Continuity:** the agreed plan becomes the shop and the cooking context.
4. **Differentiation:** Chef remembers people and explicit household truth.
5. **Control:** the plan is editable, memory is inspectable, and checkout stays
   with the person.
6. **Technology:** AI, voice, and computer use explain how Chef helps; they are
   not the main reason to care.

### Core messaging pillars

#### 1. Plan the week the way households actually decide

The household can describe schedules, participants, cravings, dislikes,
leftovers, effort, and budget in one natural request. The visible plan changes
as the conversation changes.

Customer language:

> **Talk through the week. See the plan take shape.**

#### 2. Remember the people, not just the recipes

Chef keeps household people, stated preferences, safety constraints, and
evidence as editable information. Participation and servings can change meal by
meal.

Customer language:

> **A plan that knows Tuesday is different from Thursday.**

#### 3. Carry the decision through to the shop

The confirmed plan produces a structured list with source meals, quantities,
pantry decisions, product matches, and budget context. Version 1 adds reviewed
Woolworths and Coles trolley preparation in the household's own retailer
account.

Customer language:

> **From the meals you agreed on to a shop you can trust.**

#### 4. Keep people in control

Chef explains suggestions, shows what it remembers, supports direct edits, and
pauses at consequential shopping decisions. Checkout and payment remain human
actions.

Customer language:

> **Helpful enough to do the groundwork. Careful enough to leave the final say
> with you.**

### Supporting message, not a headline

Voice is a valuable part of the experience because planning is already a spoken
household activity. It should be introduced after the visible plan story:

> Talk or type. Both change the same shared plan.

### Preferred homepage proposition

Eyebrow:

> Meal planning for real households

Headline:

> **Turn the weekly dinner conversation into a plan everyone can live with.**

Subheading:

> Tell Chef who's eating, what matters, and how the week looks. Chef helps your
> household shape the plan, build a trustworthy shopping list, and prepare a
> Woolworths or Coles trolley for you to review.

Primary CTA:

> **Start your first plan**

Secondary CTA:

> See how Chef works

Control line:

> One shared plan · Allergies stay explicit · Checkout stays yours

Differentiation line:

> Most meal planners start with recipes. Chef starts with your household—and
> carries those decisions into the shopping list.

The Coles sentence is public version 1 copy. The prelaunch replacement is
defined under [Launch modes](#12-launch-modes).

### Alternative headlines to test

- `A calmer answer to “what are we eating this week?”`
- `Plan dinner together. Carry the decision all the way to the shop.`
- `Your household's dinner decisions, turned into a plan.`

Do not rotate several messages in an uncontrolled carousel. Test one headline
at a time against a stable subheading and visual.

### Language to own

- plan together;
- real household;
- who is eating;
- see the plan take shape;
- shared plan;
- what matters to your household;
- visible and editable;
- from plan to shop;
- for review;
- checkout stays yours;
- next week gets easier.

### Language to avoid

- AI-powered recipes;
- magical meal planning;
- set and forget;
- perfect for everyone;
- autopilot checkout;
- guaranteed savings;
- allergy-safe;
- one-click order;
- ultimate recipe database;
- revolutionary;
- your personal nutritionist;
- never think about dinner again.

### Voice and tone

Marketing copy follows the product voice: concise, warm, practical, and calm.

- Lead with the useful outcome.
- Use ordinary household situations instead of abstract productivity language.
- Prefer `plan`, `shop`, and `cook` over `optimise`, `orchestrate`, and
  `workflow` in customer copy.
- Do not overuse `family` when `household` is more accurate.
- Do not make the planner feel inadequate for needing help.
- Treat allergies plainly and never as a playful personalisation example.
- Explain uncertainty and human review without defensive fine print.
- Use Australian spelling and examples in the initial market.
- Avoid exclamation marks, emoji, fake handwritten annotations, and decorative
  AI language.

## 6. Features to sell

Features should be organised by customer outcome, not by database model or
milestone number.

### Sellable feature hierarchy

| Priority | Outcome to sell | Product capabilities | Best proof | Availability |
| --- | --- | --- | --- | --- |
| 1 | **Make a plan from a real conversation** | Typed planning, household context, arbitrary date ranges, suggestions, direct edits, review and confirmation. | Split view of a normal request and the dated plan it creates. | Core implemented; voice follows in M7. |
| 2 | **Plan around actual people** | Separate people and accounts, meal-level participation and servings, explicit preferences and constraints, inspectable evidence and corrections. | One meal for two, another for four, with a visible person-specific preference. | Implemented. |
| 3 | **Keep one shared source of truth** | Family tenancy, invitations, durable plans, revisions, milestones, calendar/list views, structured UI beside conversation. | A second household member changes a meal and both see the same plan. | Implemented. |
| 4 | **Turn the plan into a trustworthy list** | Recipe versions, ingredient aggregation, serving scaling, source traceability, pantry/include state, manual staples, revisions, stale-list handling. | Expand an item to show the two meals and quantities behind it. | M4 in progress. |
| 5 | **Know what the shop is becoming** | Retail products, pack matches, substitutions, budget, estimated and actual order snapshots. | Shopping inspector with list estimate, product choice, and budget difference. | M4 version 1 requirement. |
| 6 | **Prepare the trolley without surrendering control** | Frozen list revision, Woolworths and Coles computer-use handoff, approvals, pause/cancel/takeover, reconciliation, account continuity, and human checkout. | Calm activity log ending at a reviewed trolley in the household's own retailer account, never an order-confirmation screen. | M6 version 1 requirement. |
| 7 | **Know what to cook tonight** | Today view, preparation notices, ingredients, equipment, sequential steps, timers, substitutions. | Narrow-screen cooking view at arm's length. | M5 version 1 requirement. |
| 8 | **Make next week easier** | Meal outcomes, person-specific feedback, preference candidates, explainable later recommendations. | A prior meal response visibly affecting a later suggestion. | M5 version 1 requirement. |
| 9 | **Talk when talking is faster** | Realtime voice over the same durable conversation and actions, interruption, transcript, typed fallback. | Voice waveform beside the same live plan—not a separate voice product. | M7 version 1 requirement. |

### Feature-story rules

- Always connect a capability to the household outcome.
- Demonstrate conversation and structured state together.
- Show direct editing so Chef does not look like an opaque chatbot.
- Use per-meal participation and inspectable household truth as recurring
  differentiation.
- Show list traceability before retailer automation; trust in the intent is the
  foundation for trusting the trolley.
- Show trolley preparation ending at review, not checkout.
- Keep MCP, model providers, queues, and computer-use protocol details in
  technical or trust content unless they answer a customer concern.

## 7. Offer, packaging, and pricing

### Packaging principle

Chef coordinates a household, so package it as a household.

Do not charge per person, per invited account, per plan, or by feature module at
version 1. Those boundaries fight the product's collaboration story and make
the shopper, cook, and participant feel like add-ons.

### Recommended version 1 offer

#### Chef Household

Pricing hypothesis:

- `AUD $9/month`; or
- `AUD $89/year`, shown as the recommended option;
- the annual option saves `AUD $19` against twelve monthly payments and is
  equivalent to `AUD $7.42/month`;
- one household subscription;
- unlimited authenticated household members within one household;
- unlimited non-account people represented in plans;
- unlimited meal plans, recipes, and shopping lists;
- planning, recipes, shared household memory, shopping, cooking, voice, and
  reviewed Woolworths and Coles trolley preparation included;
- fair-use or clearly stated usage limits may apply to unusually intensive AI,
  voice, or retailer-automation use if the real cost model requires them; these
  limits must not become per-person seats or ordinary plan limits;
- export and deletion available regardless of billing status.

The price is a hypothesis, not a conclusion. The lower-friction household price
is intended to make the complete weekly loop easier to try while preserving a
meaningful annual commitment. The design-partner program must validate
willingness to pay, annual selection, support burden, and gross margin against
real AI, voice, and automation cost before the price becomes a permanent public
promise.

### Trial hypothesis

Offer one complete first-plan experience or 14 days, whichever provides enough
time to reach the first shopping list. Do not require a card initially.

The trial should be designed around activation, not a countdown:

1. create the household;
2. confirm the first plan;
3. generate and review the shopping list;
4. prepare or simulate the retailer handoff when available;
5. return for the next plan.

Test card-required and card-free trials later only after the baseline funnel is
understood.

### Why not launch with freemium

- several established alternatives already give core lists and planning away;
- an artificially narrow free tier would break Chef's end-to-end promise;
- AI, voice, and automation have variable costs;
- a small paid audience produces stronger product evidence than a large dormant
  free audience;
- a public demo, sample household, or no-card first-plan trial can reduce risk
  without creating a permanent free product.

Reconsider a free tier only if a naturally low-cost, self-contained loop drives
qualified referrals into the paid household product.

### Paid design-partner offer

Recruit 12–20 households after the M4 exit gate and before broad launch.

Proposed offer:

- an eight-week guided program;
- `AUD $49` commitment fee, credited against the first annual subscription;
- founder-led onboarding and a direct feedback channel;
- weekly planning and shopping use expected;
- clear disclosure of which version 1 capabilities are still in development;
- consent requested separately for interviews, anonymised analytics, and public
  quotes;
- no promise of permanent lifetime pricing.

The purpose is to test behaviour and willingness to pay, not maximise revenue.
If even a small commitment fee prevents recruitment, diagnose the value promise
before solving the problem with a free plan.

### Future packaging questions

Do not add a second paid tier until evidence shows a distinct job or cost
boundary. Possible later boundaries include:

- multiple households managed by one account;
- additional retailers;
- unusually high automation use;
- managed asynchronous browser runs;
- professional care or support workflows.

These are not version 1 pricing-table rows.

## 8. How to sell Chef

### Start with customer development, not broad reach

The initial goal is to find households that repeat the full loop, not maximise
waitlist size.

The first acquisition motion should be founder-led:

1. Recruit households already planning at least four dinners a week.
2. Observe their existing planning and shopping process before showing Chef.
3. Have them build a real plan in Chef rather than react to a concept deck.
4. Watch the handoff into their actual weekly shop.
5. Review what changed during the week and whether Chef helped the next plan.
6. Ask for payment or renewal only after they have experienced the recurring
   loop.

### Funnel

```text
High-intent page or referral
        ↓
See a real request become a visible plan
        ↓
Plan the household's first week
        ↓
Review and confirm the plan
        ↓
Generate and trust the shopping list
        ↓
Review the prepared trolley or complete the shop
        ↓
Cook, respond, and return for the next plan
```

The marketing site should never optimise only for account creation. A signup
that does not reach a confirmed plan has not experienced the product.

### Activation and retention definitions

Initial hypotheses:

- **Visitor conversion:** starts the first-plan flow or applies for the private
  beta.
- **Setup complete:** creates a household with at least one additional person or
  explicitly confirms a one-person household.
- **Activation:** confirms a multi-meal plan and generates its shopping list
  within 48 hours.
- **First value:** uses or completes that list for a real shop.
- **Habit signal:** begins a second plan within 14 days.
- **Retained household:** completes the Plan-to-Shop loop in at least three of
  four consecutive weeks.
- **Strong version 1 value:** completes the second plan faster or with fewer
  manual corrections than the first, and reports that Chef reduced planning
  load.

The exact thresholds should change once cohort behaviour is visible.

### North-star metric

> **Households completing a second confirmed plan and usable shop within 21
> days.**

This rewards the repeated household loop rather than messages sent, recipes
saved, or accounts created.

### Supporting product and commercial metrics

- landing-page visitor to first-plan start;
- first-plan start to confirmation;
- confirmation to shopping-list generation;
- generated list to completed or reconciled shop;
- median corrections per generated list;
- trolley handoff completion and manual takeover rate;
- week-two and week-four household retention;
- invited-member participation;
- trial-to-paid conversion;
- annual versus monthly selection;
- AI and automation cost per activated and retained household;
- support minutes per retained household;
- stated planning-load change, measured with the same question over time.

### Channel order

#### 1. Direct design partners

Use founder, customer, school, work, and local-community networks to recruit a
small varied cohort. Avoid selecting only technically sophisticated friends;
the workflow must work for the household member who did not choose the tool.

#### 2. Referral inside the household loop

The natural referral moment is after a useful plan or shop, not at signup.

Prompts to test:

- invite the person who helps decide dinner;
- share a read-only summary with a household member;
- invite another household after the second successful week.

Referrals must respect household privacy and never expose safety or preference
data without an explicit share action.

#### 3. High-intent organic search

Build durable pages around the actual job. Search content is defined in
[SEO and content](#10-seo-and-content-strategy).

#### 4. Paid search

Start only after a private cohort proves activation and retention. Use focused
problem and solution terms, send each ad group to a matching static page, and
optimise toward first-plan confirmation rather than click-through rate.

#### 5. Credible household and food creators

Work with small Australian creators whose audience sees their real planning
process. A recorded Sunday plan-to-shop session is more useful than a polished
recipe sponsorship. Require clear sponsorship disclosure and let creators show
friction honestly.

#### 6. Recipe publishers and complementary products

Later partnerships may let a household bring a trusted recipe into Chef while
preserving attribution. Do not build the acquisition strategy around scraping
or republishing recipe content.

### Channels to delay

- broad paid social before a proven activation path;
- affiliate deals based solely on signup volume;
- app-store acquisition before native applications exist;
- generic AI directories;
- discount marketplaces;
- enterprise or employer-wellness sales;
- medical, nutrition, or allergy-related partnerships that imply clinical
  assurance Chef does not provide.

## 9. Advertising landing-page strategy

Each paid page should express one audience problem, demonstrate the same product
mechanism, and lead into the first-plan flow. Do not create near-duplicate pages
that differ only by swapping one keyword.

### Initial page concepts

| Route | Search or ad intent | Page promise | Primary proof |
| --- | --- | --- | --- |
| `/family-meal-planner` | family meal planner, weekly family dinner plan | Plan around the people and shape of the real week. | Different participants, preferences, and effort across several days. |
| `/meal-planner-with-shopping-list` | meal planner and grocery list | The confirmed plan becomes a traceable, editable shop. | Ingredient item expanded to its source meals. |
| `/shared-meal-planner` | shared meal planning app, meal planner for couples | Keep the decision and plan in one household workspace. | Invite, attributed change, and same visible plan. |
| `/coles-meal-planner` | Coles meal plan, add meal plan to Coles trolley | Prepare the groundwork and review the trolley before checkout. | Frozen list, calm activity log, unresolved choices, handoff. |
| `/woolworths-meal-planner` | Woolworths meal plan, add meal plan to Woolworths trolley | Prepare the groundwork and review the trolley before checkout. | Frozen list, calm activity log, unresolved choices, handoff. |
| `/weeknight-meal-planner` | quick family weeknight meals, weekly dinner planning | Fit meals to time, participation, and leftovers rather than browse endlessly. | One natural prompt becoming a realistic workweek. |

The Coles page must state that Chef is not affiliated with or endorsed by Coles
unless a formal relationship exists. Use retailer marks only with the necessary
permission and follow trademark usage guidance.

The Woolworths page requires the equivalent non-affiliation statement and
trademark discipline.

### Paid-search message examples

#### Family planning

Headline:

> Plan dinners around your actual family week

Description:

> Tell Chef who is eating, what matters, and which nights are difficult. See a
> shared plan take shape and adjust it together.

#### Plan-to-list

Headline:

> Turn the meal plan into a shopping list you can trust

Description:

> Scale quantities, keep source meals visible, add staples, and review what the
> week really needs.

#### Coles handoff — only after M6 evidence

Headline:

> Plan the week. Review the Coles trolley.

Description:

> Chef prepares the trolley from your approved list, pauses when choices need
> you, and hands control back before checkout.

Use the equivalent retailer-specific variant for Woolworths only after its M6
evidence is complete.

### Experiment discipline

- Change one major proposition at a time.
- Persist campaign and landing-page attribution through activation.
- Compare qualified starts, confirmations, shops, and retained households—not
  just clicks and signups.
- Do not use savings percentages as ad hooks until Chef has defensible evidence.
- Pause an ad group if it recruits a high volume of customers seeking a product
  Chef intentionally does not provide, such as medical diets or automated
  payment.

## 10. SEO and content strategy

### Search strategy

Chef should combine familiar category language with its differentiated
household perspective.

#### Cluster 1: plan the week

Pillar page: `/family-meal-planner`

Supporting topics:

- how to make a weekly family meal plan that survives schedule changes;
- how to plan dinners when different people eat on different nights;
- a practical Sunday meal-planning routine;
- how to plan leftovers as meals rather than accidents;
- how to involve a partner or children in choosing dinner;
- a weekly meal-plan template built around time, not recipe categories.

#### Cluster 2: turn meals into the shop

Pillar page: `/meal-planner-with-shopping-list`

Supporting topics:

- how to make one grocery list from several recipes;
- how recipe servings change shopping quantities;
- pantry check versus full pantry inventory;
- why ingredient requirements and supermarket products are different;
- how to update the shopping list when the meal plan changes;
- what to review before sending a grocery list to an online trolley.

#### Cluster 3: plan for a household

Pillar page: `/shared-meal-planner`

Supporting topics:

- meal planning for couples with different preferences;
- keeping allergies separate from dislikes in a family plan;
- how to track who is eating each dinner;
- sharing the mental load of meal planning;
- planning for guests without changing household defaults;
- how to remember food preferences without turning guesses into rules.

#### Cluster 4: use the plan tonight

Pillar page: `/how-it-works`

Supporting topics after M5:

- turning a weekly plan into tonight's cooking view;
- when to defrost or prepare ingredients for the week;
- recording meal feedback that is useful next time;
- how to adapt a recipe without losing the version the family liked.

### First six editorial pieces

Publish fewer, stronger pieces tied to real product states:

1. `A weekly meal plan should reflect who is actually home`
2. `From five recipes to one trustworthy grocery list`
3. `Allergy, dislike, or preference? Why a meal planner should keep them
   separate`
4. `The Sunday dinner conversation is already a planning system`
5. `What should happen when the meal plan changes after the list is made?`
6. `Why Chef prepares the trolley but leaves checkout to you`

Each piece should answer the question completely, use original diagrams or
product examples, and link to the relevant product page. Do not pad the site
with generic recipe roundups Chef cannot own.

### Comparison pages

Comparison pages can capture high intent, but should follow real customer
research rather than precede it.

Only publish a page such as `Chef vs AnyList` or `Chef vs ReciMe` when:

- customers repeatedly compare the products;
- every factual feature statement is current and sourced;
- the page clearly states who should choose the alternative;
- the page is maintained on a scheduled basis;
- Chef has enough public product evidence to make the comparison useful.

### Technical SEO baseline for Astro

- statically render all public acquisition and editorial pages;
- give every page a unique title, description, canonical URL, social image, and
  one clear H1;
- generate XML sitemaps and a human-useful not-found page;
- use semantic HTML before client-side components;
- keep the Shared Table mark and essential hero state lightweight;
- self-host the approved fonts and subset them where licensing permits;
- use responsive images with explicit dimensions and modern formats;
- reserve hydration for interactions that materially need it;
- meet the [accessibility baseline](STYLE.md#accessibility-baseline);
- use `SoftwareApplication` or `WebApplication` structured data only with real,
  visible attributes;
- use `FAQPage` structured data only where the same questions and answers are
  visibly rendered and current;
- never add aggregate ratings, price, availability, or review data that the page
  does not genuinely support;
- keep content in typed Astro collections so dates, authors, descriptions,
  social images, and update status are validated at build time;
- add automated link, structured-data, sitemap, and production-build checks.

### Site and application boundary

Recommended starting topology:

- Astro owns the public root domain and marketing routes;
- Laravel owns the authenticated product on an `app` subdomain;
- `Log in` goes directly to the application;
- `Start your first plan` creates or resumes the application onboarding flow;
- campaign attribution is transferred with explicit, privacy-conscious
  parameters and retained server-side after signup;
- authentication, billing, privacy, and support links stay consistent across
  both surfaces.

A same-domain reverse-proxy arrangement is also valid if it simplifies cookies
or deployment. The important boundary is that Astro does not reimplement Chef's
domain or become a second application frontend.

## 11. Marketing-site information architecture

### Launch navigation

Primary navigation:

- How it works
- Meal planning
- Shopping
- Pricing

Secondary or footer navigation:

- Household memory
- Security and privacy
- Human checkout
- Accessibility
- About
- Billing and cancellation
- Help and support
- Log in

Persistent primary CTA:

- prelaunch: `Join the private beta`;
- public version 1: `Start your first plan`.

Do not add a large `Features` mega-menu at launch. Four or five clear choices
better match the product's calm, progressive style.

### Page inventory

| Route | Role | Launch priority | Primary CTA |
| --- | --- | --- | --- |
| `/` | Express the category, differentiated loop, proof, and offer. | Essential | Join beta or plan first week |
| `/how-it-works` | Show the Plan → Review → List → Trolley → Cook and learn sequence. | Essential | Plan first week |
| `/meal-planning` | Explain conversation plus visible, editable household planning. | Essential | Start a plan |
| `/shopping` | Explain list traceability, pantry decisions, product matching, budget, and trolley handoff. | Essential when M4 is complete | Build the first plan |
| `/household-memory` | Explain people, participation, explicit preferences, constraints, evidence, and correction. | High | Start a plan |
| `/cooking` | Explain Today, preparation, steps, substitutions, outcomes, and feedback. | Publish after M5 | Start a plan |
| `/retailer-handoff` | Explain Woolworths and Coles scope, account continuity, approval, takeover, reconciliation, and human checkout. | Publish after M6 | Try with a plan |
| `/pricing` | Set one household offer, trial, inclusions, and billing FAQ. | Essential before paid beta | Start trial |
| `/private-beta` | Qualify design partners and set expectations honestly. | Essential now | Apply for access |
| `/security-and-privacy` | Explain household data, AI boundaries, permissions, screenshot retention, retailer control, export, and deletion. | Essential before public launch | Read policy or start trial |
| `/about` | Tell the product thesis and why the Shared Table exists. | Useful | Join beta |
| `/journal` | House original, job-focused guides and product decisions. | Useful | Read or start plan |
| `/help` | Product support and setup documentation. | Required by public launch | Contact support |

Focused acquisition pages from [Advertising landing-page strategy](#9-advertising-landing-page-strategy)
should reuse the same components and product proof while maintaining a distinct
search intent.

## 12. Launch modes

The marketing site needs two honest modes rather than one set of copy that gets
ahead of the product.

### Mode A: private beta and design partners

Use while M4–M9 remain incomplete.

Primary goal:

- recruit qualified households;
- explain the vision;
- demonstrate implemented planning and shopping work;
- set clear participation expectations;
- collect no more demand than the team can support.

Preferred hero:

Eyebrow:

> Private family beta in Australia

Headline:

> **Help shape a calmer way to plan dinner together.**

Subheading:

> Chef turns an ordinary household conversation into a visible meal plan and
> shopping list. We are inviting a small group of Australian households to use
> it for their real week and help us finish the journey to cooking and a
> reviewed Woolworths or Coles trolley.

Primary CTA:

> Apply for the private beta

Secondary CTA:

> See what is working now

Expectation line:

> Best for households that plan four or more dinners a week and are happy to
> share practical feedback.

The page should visibly label future voice, cooking, and retailer-handoff states.
Do not show them as active navigation or use ambiguous `Get started` copy.

### Mode B: public version 1

Switch only after M9 exit evidence is satisfied.

Primary goal:

- move a qualified visitor into the first real plan;
- demonstrate the complete household loop;
- make price, control, and privacy clear;
- support self-serve evaluation.

Use the preferred proposition from [Messaging system](#5-messaging-system) and
replace beta application CTAs with `Start your first plan`.

### Transition checklist

- update the claim ledger against completed milestone evidence;
- replace future-state visuals with screenshots or recordings from the release
  candidate;
- publish pricing, trial terms, cancellation, support, privacy, and terms;
- remove beta qualification language and explain normal onboarding;
- publish the Coles affiliation disclaimer and automation boundaries;
- validate all structured data and social cards;
- test signup attribution and the first-plan deep link;
- review desktop and narrow-screen paths;
- archive or redirect beta-only pages intentionally.

## 13. Page briefs and rough copy

These briefs are the content basis for Paper wireframes. Copy is directional and
should be tested against real household language before public launch.

### Homepage

#### Job

Help the right household understand the product in under a minute and believe
that Chef handles more than recipe suggestions.

#### Section order

1. quiet header and one primary CTA;
2. hero with the conversation-to-plan proof;
3. short household-control line;
4. the mental-load problem;
5. five-step product sequence;
6. household-memory differentiation;
7. plan-to-shop proof;
8. control, privacy, and human-checkout boundary;
9. real customer proof when available;
10. one-plan pricing summary;
11. concise FAQ;
12. final first-plan CTA.

Omit the customer-proof section entirely until attributable household evidence
is available. Do not ship an empty testimonial framework or internal evidence
instructions on the public site.

Use `Start your first plan` consistently for the public-version primary action
across the header, hero, pricing, sticky mobile action, final CTA, and footer.
Place `14 days free · No card required` beside the first conversion opportunity,
not only in the pricing section. The compact scrolled header must retain the
primary action, and the narrow-screen page must preserve the problem, five-stage
sequence, household-memory proof, plan-to-shop proof, pricing, FAQ, and final CTA
rather than collapsing directly from hero to price. Add the evidence section on
both widths once real customer proof is available.

#### Hero

Use the preferred public or private-beta hero for the active launch mode.

The hero visual should be a product composition rather than a generic device
mockup:

- left: the example household request;
- centre: the emerging five-meal plan;
- right or overlay: the relevant household truths and participants;
- one subtle transition or sequence can show the confirmed plan becoming a
  shopping list;
- the visual must remain legible as a static image and with reduced motion.

#### Problem section

Heading:

> **Dinner is a team decision hiding in one person's head.**

Body:

> The week lives across messages, calendars, remembered dislikes, recipes,
> supermarket tabs, and the person trying to hold it all together. Chef gives
> the conversation somewhere useful to land.

#### How-it-works summary

Heading:

> **One conversation. One plan. The rest of the week connected.**

Steps:

1. **Talk through the week**  
   Say who is home, what sounds good, what needs using, and which nights need to
   be easy.
2. **See the plan take shape**  
   Meals, participants, servings, and open decisions become visible as you
   talk. Move or edit anything directly.
3. **Build the list from what you agreed**  
   Chef builds a traceable list, keeps pantry and staple decisions visible, and
   helps review products and budget.
4. **Let Chef prepare the trolley**  
   Use the Chrome extension to add the planned ingredients and ordinary
   household groceries in the household's own Woolworths or Coles account.
   Saved products, points, rewards, and delivery options remain attached to
   that account. Chef pauses for review before checkout.
5. **Cook, respond, and make the next week easier**  
   Open tonight's meal, keep the useful adjustments, and let real feedback
   improve later suggestions.

For the private-beta site, label steps 3 and 4 with their actual availability.

#### Household-memory section

Heading:

> **It remembers Mia dislikes mushrooms. It does not decide that Mia has an
> allergy.**

Body:

> Chef keeps people, stated preferences, safety constraints, and the evidence
> behind them visible. You can correct or remove what it remembers, and each
> meal can have its own participants and servings.

Support points:

- people and accounts are not forced to be the same;
- allergies stay separate from dislikes;
- tentative patterns remain labelled as inferences;
- household members can inspect the same plan;
- direct controls remain available when talking is not the fastest option.

#### Shopping section

Heading:

> **A shopping list with reasons, not just rows.**

Body:

> See which meal created each item, how servings changed the quantity, what is
> already in the pantry, and what changed after the plan was confirmed. Add the
> ordinary household staples without losing the link back to dinner.

Version 1 continuation:

> Review the product matches and estimated total, then let Chef prepare the
> Woolworths or Coles trolley in the account you already use. It pauses when a
> decision needs you and hands control back before checkout.

#### Retailer-trolley section

Heading:

> **Your trolley, prepared in the account you already use.**

Body:

> Chef's Chrome extension adds the planned ingredients and the rest of your
> groceries to Woolworths or Coles. Because it works in your account, saved
> products, points, rewards, and delivery options stay with you. Chef prepares
> and reconciles the trolley; you review it and click checkout.

Target outcome, publish only after measured validation:

> **Plan the week and reach a ready-to-review trolley in one focused 5–10
> minute session.**

#### Control section

Heading:

> **The helpful parts are automatic. The consequential parts are yours.**

Body:

> Chef can suggest, organise, match, and prepare. You can inspect the plan,
> change the list, pause the retailer work, take over at any time, and review the
> final trolley. Chef never checks out or pays for you.

#### Final CTA

Heading:

> **Plan it. Prepare the trolley. Cook from the same shared week.**

Body:

> Tell Chef who's eating and what is different this week. Review the plan and
> trolley, then keep every meal and recipe at your fingertips on laptop or
> phone.

CTA:

> Start your first plan

### How it works

#### Hero

Headline:

> **From “what should we eat?” to a week the household can use.**

Subheading:

> Chef carries the decision through four connected stages: plan, review, shop,
> then cook and learn.

#### Page structure

Use one continuous example household across the page.

1. **Plan** — show the natural request, contextual follow-up, suggestions, and
   emerging plan.
2. **Review** — show direct meal moves, participants, servings, recipe snapshot,
   effort, and unresolved decisions.
3. **Shop** — show aggregation, pantry choices, list traceability, products,
   budget, and trolley preparation.
4. **Cook and learn** — show Today, preparation notice, steps, substitutions,
   outcome, and person-specific response.

End each stage with the durable state created. This reinforces that Chef is not
a chat transcript.

Closing line:

> Conversation carries the intent. The plan, list, recipe, and household memory
> carry the truth.

### Meal planning

#### Hero

Headline:

> **Plan around the household you have, not an imaginary perfect week.**

Subheading:

> Different people, changing schedules, leftovers, easy nights, guests, and
> open slots all belong in the plan.

#### Sections

- conversation plus visible state;
- household people and meal-level participation;
- flexible date ranges and meal occasions;
- recipes, custom meals, leftovers, takeaway, eating out, and open nights;
- direct calendar and list controls;
- explanations and explicit review before confirmation;
- shared household access and revisions.

Primary visual:

Show Tuesday for two people, Thursday for four, Friday as takeaway, and Sunday
as leftovers. A generic seven-card recipe grid will miss the point.

### Shopping

#### Hero

Headline:

> **Make the list from the plan—not from memory in the supermarket aisle.**

Subheading:

> Chef combines recipe requirements, scales them to the people eating, keeps the
> source meals attached, and leaves room for pantry decisions and ordinary
> household staples.

#### Sections

- plan-derived quantities and compatible-unit aggregation;
- traceability to exact recipe versions and meals;
- document-like editing backed by structured rows;
- pantry, include, staple, note, and completion state;
- stale-plan explanation and regeneration;
- product matching, substitution preferences, and budget;
- reviewed Woolworths and Coles trolley preparation and reconciliation.

Do not lead this page with cross-retailer price savings. The product's stronger
story is trustworthy intent and a controlled handoff.

### Household memory

#### Hero

Headline:

> **Chef should remember what matters—and show its work.**

Subheading:

> See the people, preferences, constraints, and evidence behind the plan. Correct
> anything without losing the history of what changed.

#### Sections

- user accounts versus meal participants;
- explicit safety constraints;
- stated preferences versus tentative inferences;
- source evidence and correction;
- person-specific feedback and household disagreement;
- privacy, export, removal, and team isolation.

This page is a product feature page and a trust asset. Avoid anthropomorphic
claims that imply Chef knows more than the structured records it can show.

### Woolworths and Coles handoff

Publish only after M6 exit evidence.

#### Hero

Headline:

> **Let Chef prepare the trolley. Keep checkout in your hands.**

Subheading:

> Start from an approved shopping list, watch the progress, resolve substitutions
> or budget changes, and review the Woolworths or Coles trolley before taking
> over.

#### Sequence

1. Freeze the list being used.
2. Choose the approved Woolworths or Coles tab.
3. Match and add ordinary products.
4. Pause for material substitutions, budget issues, or sensitive steps.
5. Reconcile the trolley against the list.
6. Hand control back before authentication, checkout, and payment.

Trust callout:

> Retailer pages and on-screen instructions cannot expand Chef's permission.
> Pause, cancel, and manual takeover remain available throughout the run.

Add clear statements that Chef is not affiliated with or endorsed by
Woolworths or Coles unless that changes.

### Pricing

#### Hero

Headline:

> **One household. The whole weekly loop.**

Subheading:

> Plan together, shop from the agreed week, cook what is next, and make later
> plans easier. No per-person seats.

#### Price card

Name:

> Chef Household

Price hypothesis:

> $89 AUD / year  
> or $9 month to month

Show the annual option as selected by default with `Save $19` and the equivalent
`$7.42/month` beneath the yearly price. Design and implement both selector states
explicitly; changing the billing period must update the displayed price and the
checkout destination without changing the included product.

Inclusions:

- one shared household;
- unlimited invited household members;
- planning conversation and direct plan controls;
- household people, preferences, and explicit safety constraints;
- unlimited flexible meal plans, recipes, and shopping lists;
- structured shopping lists, products, and budget;
- reviewed Woolworths and Coles trolley preparation;
- cooking mode and household feedback;
- typed and voice planning;
- export, cancellation, and deletion controls.

CTA:

> Start your first plan

Support line:

> Try the complete first-plan experience without entering a card.

Trial clarification:

> Nothing is charged because no card is required. Choose monthly or annual
> billing only if you want to continue.

Do not include invented `most popular` badges, crossed-out anchor prices, or a
three-column tier table when there is only one meaningful offer.

#### Pricing FAQ

**Is the price for the whole household?**  
Yes. `AUD $9` monthly or `AUD $89` annually covers one household, including
invited members and the people represented in meal plans.

**What does unlimited include?**  
Unlimited household members, meal plans, recipes, and shopping lists. Fair use
applies only to unusually intensive AI, voice, or retailer automation, not
ordinary household planning.

**What happens after the 14-day trial?**  
Nothing is charged because no card is required. Choose monthly or annual
billing only if you want to continue.

**Will Chef place my grocery order?**  
No. Chef can prepare a Woolworths or Coles trolley for review in your own
retailer account. Delivery details, checkout, and payment remain your actions.

**Do I need to use voice?**  
No. The complete workflow remains available through typing and direct controls.

**Can I cancel or change billing periods?**  
Yes. Cancel any time and keep access until the end of the paid period.
Billing-period changes take effect at the next renewal.

**What happens to my household data?**  
Export or delete household data regardless of billing status. Link to the
security and privacy page for provider, retention, and retailer-access
boundaries.

### Private beta

#### Hero

Headline:

> **Use Chef for your real week. Help us make the whole loop trustworthy.**

Subheading:

> We are looking for a small group of Australian households that already plan
> several dinners a week and will use Chef through planning and shopping for
> eight weeks.

#### Qualification content

`This is likely a fit if:`

- one person currently carries most of the planning load;
- at least two people influence the plan;
- you normally plan four or more dinners;
- you are comfortable using a responsive web app;
- you can share short practical feedback each week;
- you understand that cooking, voice, and retailer automation may arrive during
  the program rather than on day one.

`What you receive:`

- direct onboarding;
- early access to each completed stage;
- a direct support and feedback channel;
- the commitment fee credited toward the first annual plan;
- clear release notes and a simple way to leave the program.

CTA:

> Apply for the private beta

Application fields should be short: household shape, current planning method,
meals planned per week, primary retailer, online-shopping use, biggest pain,
and willingness to participate for eight weeks. Do not collect allergy details
on the marketing site.

### Security and privacy

This should be written in household language with links to the full legal
policies.

Questions to answer visibly:

- Who can see a household's plans and preferences?
- What does Chef send to AI providers and why?
- Does customer data train public models?
- How are voice transcripts handled?
- What retailer-tab access does the extension receive?
- How long are screenshots and automation artifacts retained?
- Can the person pause or revoke access?
- Does Chef store retailer credentials or payment details?
- How can a household export or delete its data?
- What happens when a member leaves the household?

The intended public-version answers are:

- only authorised household members can access that household's plans,
  preferences, and history;
- Chef sends AI providers only the context needed for the response or requested
  task, while permanent credentials remain server-side;
- Chef does not use household content to train public models;
- voice uses the same conversation history as typing, and transcripts can be
  reviewed and deleted with household data;
- retailer access is limited to a user-approved tab and validated actions within
  the agreed shopping task;
- screenshots are retained only for approvals, reconciliation, or debugging,
  then removed under the short period stated in the privacy policy;
- Chef does not store retailer passwords or payment details;
- optional permissions and retailer activity can be paused, revoked, or taken
  over;
- export and deletion remain available regardless of billing status;
- leaving removes the member's access, while household history remains with the
  household and personal account data can still be exported or deleted.

Do not publish answers until the implementation and legal policies support
them. This page should become a release gate artifact, not marketing
reassurance detached from the product.

## 14. Shared components for the Astro site

The first design and build should establish a small set of reusable marketing
patterns:

- `MarketingHeader`
- `MarketingFooter`
- `Hero`
- `ProductProof`
- `ConversationToPlanDemo`
- `StageSequence`
- `FeatureNarrative`
- `HouseholdTruthExample`
- `ShoppingTraceExample`
- `AutomationActivityExample`
- `ControlCallout`
- `CustomerEvidence`
- `PricingOffer`
- `FaqList`
- `ArticleCard`
- `FinalCta`

Components should be content-led and accept structured content rather than
encode one page's exact copy. Avoid a library of interchangeable decorative
cards that flattens every story into the same layout.

### Visual direction

- Apply the Shared Table mark, white canvas, ink, paprika, sage, oat, Source
  Sans 3, and Newsreader exactly as defined in [BRAND.md](BRAND.md).
- Let real plan, conversation, list, and cooking states carry the visual story.
- Use white as the dominant canvas; oat is not a beige page background.
- Use editorial Newsreader selectively for brand-level headings, not every
  block.
- Keep navigation quiet and the primary CTA obvious.
- Avoid glossy food photography, recipe-catalogue grids, chef hats, utensils,
  food emoji, robot imagery, gradients, and ambient AI effects.
- Use motion only to explain conversation becoming structured state or one
  stage becoming the next.
- Ensure the proof survives as a static composition, at narrow widths, and with
  reduced motion.

## 15. Paper design handoff

The first Paper session should test the commercial story and page rhythm before
polishing a full visual system.

### Prototype these pages first

1. **Homepage — desktop**  
   Test the proposition, conversation-to-plan proof, stage sequence, household
   memory, shopping story, control boundary, and final CTA.
2. **Homepage — narrow screen**  
   Test whether the hero proof remains comprehensible when conversation, plan,
   and household truth become a sequence rather than three columns.
3. **How it works — desktop**  
   Test the continuous Plan → Review → List → Trolley → Cook narrative.
4. **Pricing — desktop and narrow screen**  
   Test whether one household offer feels complete without a comparison table.
5. **Private beta — desktop**  
   Test qualification, expectations, and application flow.
6. **One acquisition page**  
   Start with `/meal-planner-with-shopping-list` to test a focused search/ad
   page against the broader homepage.

### Essential frames and states

- header at top and after scroll;
- preferred public hero and private-beta hero variants;
- example request before sending;
- plan visibly populated from the request;
- direct edit or meal move;
- household truth expanded with source evidence;
- confirmed-plan handoff into shopping;
- shopping item expanded to source meals;
- calm trolley activity with one unresolved decision;
- human-checkout boundary;
- single pricing offer;
- FAQ and final CTA;
- mobile navigation and vertically sequenced product proof.

### Prototype content

Use one fictional household consistently:

- four household people;
- Tuesday has two participants;
- Thursday requires a quick meal;
- one explicit dislike attached to one person;
- one ingredient to use early;
- one leftover meal;
- one open or eating-out slot;
- one item aggregated from two recipes;
- one product substitution that requires review.

Do not use real private-beta household data in design files.

### Questions the Paper prototype must answer

- Does the visitor understand that Chef is more than a recipe generator?
- Is conversation visibly connected to editable structured state?
- Does the household differentiation appear before the visitor assumes Chef is
  another personal meal planner?
- Does the page show the full loop without making the product feel sprawling?
- Is the human-control boundary reassuring rather than alarming?
- Is the primary action obvious at every major scroll depth?
- Does the mobile story preserve cause and effect?
- Can the private-beta page explain incomplete version 1 work without making
  the current product feel hypothetical?

### Paper-to-Astro handoff

Before implementation:

- name each reusable section and state in Paper;
- annotate which content is implemented, future version 1, or evidence-dependent;
- extract typography, colour, spacing, radius, and breakpoint decisions into a
  small marketing token map derived from [BRAND.md](BRAND.md);
- identify real product captures that must replace illustrative placeholders;
- define reduced-motion and narrow-screen behaviour for every animated proof;
- record page-specific copy outside visual layers so the Astro content remains
  reviewable and searchable;
- validate the final page outline against this document before component work.

## 16. Validation plan

### Customer questions

Use interviews to understand existing behaviour, not invite compliments about
the concept.

- Walk me through the last time you decided this week's dinners.
- Who affected the decisions, and how did their input reach the plan?
- Where did the final plan live?
- What changed after you made it?
- How did you turn it into the shop?
- What did you have to enter or check again?
- When did the plan stop being useful?
- What have you tried before, and why did you stop?
- Which part would you trust Chef to prepare without asking you first?
- Which part would you always want to review?
- What would make this worth paying for each month?

Do not start by asking `Would you use an AI meal planner?`

### Message tests

Test three propositions with the same product proof:

1. household coordination: `Turn the weekly dinner conversation into a plan`;
2. mental load: `A calmer answer to what are we eating this week?`;
3. end-to-end continuity: `From the plan everyone agreed on to the shop`.

Measure qualified first-plan starts and follow-through, then ask activated
households which promise matched the value they actually received.

### Pricing tests

- ask design partners to pay the commitment fee;
- offer monthly and annual version 1 prices to qualified households;
- record objections in the customer's words;
- test whether the full loop, not a feature limit, explains the premium;
- compare willingness to renew after the second completed week rather than
  immediately after an impressive first demo;
- verify gross margin with real model, voice, automation, support, payment, and
  infrastructure costs before fixing the public price.

### Evidence to collect

- before-and-after planning process maps;
- time spent in the same defined Plan-to-Shop segment;
- number and type of manual corrections;
- whether another household member participated;
- list completion or trolley reconciliation;
- repeat use and week-two start time;
- support burden and failure recovery;
- short attributed quotes tied to a specific product outcome;
- permission for every public quote, image, or case study.

## 17. Decisions to make before the Astro build

These decisions materially affect the first marketing implementation:

1. Confirm whether the first live site is private-beta mode or a waitlist that
   precedes usable access.
2. Confirm the paid design-partner structure and commitment fee.
3. Choose the initial domain and application subdomain strategy.
4. Complete naming, trademark, domain, social, and app-store checks.
5. Decide whether the first-plan trial is time-based, outcome-based, or both.
6. Validate the price with real households and the cost model.
7. Select the real product dataset and fictional names used for public demos.
8. Define privacy, analytics, consent, and campaign-attribution boundaries.
9. Decide which M4 product state is strong enough to show publicly.
10. Set the beta application capacity and response expectation.
11. Decide whether founder story and identity should appear on the About page.
12. Establish who owns copy review, product screenshots, research updates, and
    claim approval as milestones change.

## 18. Recommended immediate sequence

1. Approve or revise the category, primary customer, proposition, and
   household-pricing direction in this document.
2. Interview five to eight target households using their last real planning
   week as the evidence.
3. Finish and validate the M4 exit gate with several real plans and shops.
4. Decide the paid design-partner offer and beta capacity.
5. Use this brief to prototype the homepage, how-it-works, pricing, private-beta,
   and plan-to-list landing page in Paper.
6. Test the prototype and message hierarchy with target households before visual
   polishing.
7. Create the Astro application only after the page rhythm and shared components
   are understood.
8. Launch in private-beta mode and measure through confirmed plan, usable shop,
   and second-week return.
9. Replace future-state copy and visuals only as milestone evidence is completed.
10. Switch to public version 1 acquisition mode only after the M9 release gate.

The durable commercial principle is simple: **Chef earns the right to sell the
whole week by proving that each household decision survives the handoff to the
next stage.**
