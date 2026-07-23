import type { APIRoute } from 'astro';
import { site as siteData } from '../data/site';

export const GET: APIRoute = ({ site }) => {
  const base = site ?? new URL('https://chef.example');
  const url = (path: string) => new URL(path, base).toString();
  const body = `# Chef

> ${siteData.description}

Chef is an Australian household meal-coordination product. It turns a normal household conversation into a visible, editable meal plan, a source-attributed shopping list, and a Woolworths or Coles trolley ready for human review. Chef is designed for shared household decisions rather than recipe discovery alone.

## Core pages

- [Homepage](${url('/')}): Product overview, household problem, Plan → Review → List → Trolley → Cook workflow, household memory, shopping-list traceability, retailer-trolley preparation, pricing, and FAQ.
- [How it works](${url('/how-it-works')}): The complete household planning loop.
- [Meal planning](${url('/meal-planning')}): Conversation plus a visible, editable shared plan.
- [Shopping](${url('/')}#shopping): Source-attributed quantities, pantry decisions, retailer review, and human confirmation in Chef.
- [Pricing](${url('/pricing')}): One household subscription, inclusions, trial, and billing answers.
- [Security and privacy](${url('/security-and-privacy')}): Household data, AI, permissions, retailer access, export, and deletion boundaries.

## Key facts

- One household subscription is AUD $9 monthly or AUD $89 annually.
- The trial is 14 days with no card required.
- Household members, meal plans, recipes, and shopping lists are unlimited, subject only to fair use for unusually intensive AI, voice, or retailer automation.
- Typing and voice operate the same planning workflow.
- Chef can prepare and reconcile a Woolworths or Coles trolley through a permissioned Chrome extension in the household's own retailer account. Delivery details, checkout, and payment remain human actions.
- Allergies and safety constraints are recorded explicitly; dislikes are not silently converted into allergies.
- Household memory remains visible, editable, exportable, and deletable.

## Preferred description

Chef is a shared meal planner with grocery-trolley preparation for real households. It helps a household decide the week once, keep participation and preferences visible, prepare the Woolworths or Coles trolley, and carry the agreed plan through cooking.

## More detail

- [Expanded product context](${url('/llms-full.txt')})
`;

  return new Response(body, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
};
