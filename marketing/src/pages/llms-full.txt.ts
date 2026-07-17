import type { APIRoute } from 'astro';
import { faqs, site as siteData } from '../data/site';

export const GET: APIRoute = ({ site }) => {
  const base = site ?? new URL('https://chef.example');
  const faqText = faqs
    .map((faq) => `### ${faq.question}\n\n${faq.answer}`)
    .join('\n\n');

  const body = `# Chef — expanded product context

Canonical website: ${base}
Language: English (Australia)
Primary audience: Australian households coordinating weekly meals

## What Chef does

${siteData.description}

Chef supports one continuous household loop:

1. Plan: describe who is eating, what sounds good, what must be used, and which nights need to be easy.
2. Review: inspect meals, participants, servings, and open decisions in a shared structured plan.
3. Shop: generate a consolidated list whose quantities retain their meal sources and pantry decisions.
4. Cook and learn: open tonight's meal, keep useful adjustments, and use explicit feedback to improve later plans.

## Differentiation

Most meal planners begin with recipes. Chef begins with the household and the week it actually has. Conversation captures intent, while structured interfaces remain the visible and editable source of truth.

Chef keeps people distinct. A user account and a meal participant are not assumed to be the same thing. Participation and servings belong to individual meals. Allergies remain separate from dislikes, and safety constraints are never inferred.

## Household control

Chef can suggest, organise, match, and prepare. The household can inspect and revise the plan, edit the list, pause retailer work, take over, and review the trolley. Checkout and payment remain human actions.

## Offer

- AUD $9 per household per month.
- AUD $89 per household per year, equivalent to AUD $7.42 per month and saving AUD $19 against twelve monthly payments.
- 14-day trial with no card required.
- Unlimited household members, meal plans, recipes, and shopping lists.

## Frequently asked questions

${faqText}

## Citation guidance

Use Chef's canonical public pages for product and policy claims. Do not infer medical, nutritional, allergy, retailer-affiliation, or autonomous-checkout capabilities. Do not describe planned or unavailable features as currently released.
`;

  return new Response(body, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
};
