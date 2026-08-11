# Chef commercial strategy

Status: pre-public-beta commercial direction for the Coles basket journey.

Chef is currently a conversation-led household meal-planning product. Its
commercial promise must match the implemented journey:

1. talk through the coming days or week;
2. turn that conversation into a visible, editable plan;
3. approve the complete plan;
4. prepare a reliable batch of recipes;
5. build a grocery plan and prepare a verified, restorable Coles basket;
6. leave checkout with the household;
7. cook, record outcomes, and carry reviewed preferences into later planning.

Chef contains this Coles-first implementation, but all retailer flags are off by
default. It is not a public commercial claim until independent legal/privacy
review, PostgreSQL/Redis operations, a live Coles pilot, real-device native
authentication, production dependency advisory remediation or documented
mitigation, and staged cohort evidence are complete. The global mutation
circuit breaker must remain immediate and independent of deployment.

Chef does not select fulfilment, check out, pay, handle restricted products, or
place orders. Public and cohort copy must make that human-control boundary
prominent.

## Audience

The initial audience is Australian households where one adult carries most of
the cognitive work of deciding what to cook, while the consequences of those
decisions are shared.

Strong early-fit signals include:

- planning several dinners each week;
- coordinating preferences, participation, leftovers, time, and budget;
- wanting another household member to understand or contribute to the plan;
- repeating the same planning conversation across notes, messages, memory, and
  recipe tabs;
- valuing a usable plan and recipes more than a catalogue of generic ideas.

Chef is not initially positioned for professional kitchens, nutrition
prescription, medical diet management, or households that want an autonomous
checkout agent. Basket preparation is reversible assistance; purchase remains
the account owner's act.

## Positioning

Until retailer release gates pass:

> Chef helps a household talk through the week, agree on a realistic meal plan,
> and turn it into recipes they can actually cook.

For an explicitly enabled Coles beta cohort:

> Chef turns an agreed household meal plan into recipes and a verified Coles
> basket, while checkout stays with you.

The useful distinction is not “AI meal ideas.” Chef preserves household truth,
who is eating, the accepted meal, the recipe version used, cooking progress,
and feedback as durable state. Conversation makes planning easier; structured
state makes the result trustworthy.

All claims should lead with:

- planning around the actual household and date range;
- a conversation that becomes a visible plan;
- one approval for the complete plan;
- reliable recipe preparation and retry;
- recipe versions that remain attached to planned meals;
- cooking progress, outcomes, and reviewable learning.

Only an enabled beta cohort may additionally claim:

- deterministic grocery requirements sourced back to recipes;
- current Coles product/pack selection after hard availability and safety
  checks;
- a restorable replacement of the existing Coles basket;
- verified line quantities, captured totals, and explicit uncertain states.

Never claim or imply:

- autonomous checkout or payment;
- delivery or collection slot selection;
- order placement;
- guaranteed Coles availability or checkout prices;
- contractual endorsement by Coles;
- that low-confidence products can bypass hard constraints;
- inferred allergies or medical guidance.

## Activation and retention

Core activation is a household completing its first useful planning-to-recipes
journey:

- at least two planned meals;
- known participants;
- explicit safety context where applicable;
- whole-plan approval;
- every required recipe prepared successfully.

Beta activation additionally requires:

- the Coles account owner completing the disclosure and connection;
- every grocery requirement receiving a valid product;
- a final basket snapshot confirmed from Coles;
- the household opening the basket review before human checkout.

The first retention signal is a second approved plan that uses household truth
or prior feedback without forcing the household to restate everything.

Useful product measures include:

- time from first planning message to approved plan;
- readiness-blocker mix and consolidated-clarification rate;
- provisional participant suggestion correction rate;
- proportion of started plans that reach approval;
- recipe-batch completion and retry rate;
- grocery-plan completion and no-valid-product rate;
- basket ready, failed, uncertain, and restoration outcomes;
- time from approval to verified basket and live-session reauthentication rate;
- silent standing-connection reuse and reauthentication completion rate;
- product recovery, budget-review, run-override, and revised-plan reapproval
  outcomes;
- explicit alternative preference saves and later valid-SKU reuse;
- recipes opened from an approved plan;
- planned meals entering cooking mode;
- outcomes and participant feedback recorded;
- households completing another approved plan within 21 days.

These measures should be derived from Chef's durable application state. Avoid
optimising for chat volume, generated text, or speculative downstream intent.

## Acquisition

Before beta release, acquisition should demonstrate the implemented
planning-to-recipes journey with real product footage and precise copy. Good
entry points include:

- planning dinners for a household with different preferences;
- deciding who is eating on which night;
- turning a messy week into a shared plan;
- preparing a complete set of recipes after approval;
- retaining the exact recipe used and learning from meal feedback.

After the gated pilot, cohort material may also demonstrate a real non-empty
basket replacement, pack choice, confirmed total, and restoration. Never use a
mock animation as evidence of live retailer capability.

Product-led research should observe the household's current planning process
before introducing Chef. Recruitment, landing pages, and demonstrations must
distinguish current capability from future concepts.

## Pricing hypothesis

Pricing remains a hypothesis until repeated household use and serving costs are
measured. A simple household subscription is preferable to feature gates that
fragment the core journey.

Any trial should let a household complete at least one full plan, recipe batch,
and cooking outcome before asking for payment. Coles basket preparation should
not carry a separate surcharge until live success, support burden, Browserbase
cost, and restoration rates are known.

## Evidence and release discipline

Commercial language follows the same truth boundary as the product:

- root product documentation defines current capability;
- milestone completion requires working code and test evidence;
- marketing concepts are labelled as concepts;
- provider, model, and queue details remain implementation concerns;
- safety and tenancy claims require direct test coverage;
- no retailer integration is described as publicly available before live
  evidence and legal/privacy approval exist;
- enabled-cohort copy links the
  [Coles online-safety guidance](https://www.coles.com.au/help/safety/online-safety)
  and
  [Customer Agreement](https://www.coles.com.au/important-information/customer-agreement?cid=wsm)
  and does not obscure their account-sharing risk;
- rollout moves disabled → read-only discovery → internal mutation pilot →
  limited external cohort → public beta;
- checkout, fulfilment, restricted products, and order placement require a
  separate product and trust decision.
